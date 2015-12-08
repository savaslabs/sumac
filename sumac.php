#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Harvest\HarvestAPI;
use Harvest\Model\Range;
use Carbon\Carbon;

$console = new Application();
$console
  ->register('sync')
  ->setDefinition(
      array(
          new InputArgument(
              'date',
              InputArgument::OPTIONAL,
              'Date to sync data for. Defaults to current day.',
              Carbon::create()->format('Ymd')
          ),
          new InputOption('update', 'u', null, 'Update existing time entries.'),
          new InputOption('strict', 's', null, 'Require project map to be defined.'),
          new InputOption('dry-run', 'd', null, 'Do a simulation of what would happen'),
      )
  )
      ->setDescription('Pushes time entries from Harvest to Redmine')
      ->setCode(function (InputInterface $input, OutputInterface $output) {
        $range = $input->getArgument('date');
        if (strpos($range, ':') !== false) {
            list($from, $to) = explode(':', $range);
        } else {
            $to = $from = $range;
        }
        $range = new Range($from, $to);
        $output->writeln('<question>Syncing data for time period between '.$from.' and '.$to.'</question>');

      // Load the configuration.
        $yaml = new Yaml();
        $config = $yaml->parse(file_get_contents('config.yml'));
        if (!$config) {
            $output->writeln('<error>Could not load the config.yaml file.</error>');

            return;
        }

      // Initialize the Harvest client.
        $harvest = new HarvestAPI();
        $harvest->setUser($config['auth']['harvest']['mail']);
        $harvest->setPassword($config['auth']['harvest']['pass']);
        $harvest->setAccount($config['auth']['harvest']['account']);

      // Initialize the Redmine client.
        $redmine_client = new Redmine\Client(
            $config['auth']['redmine']['url'],
            $config['auth']['redmine']['user'],
            $config['auth']['redmine']['pass']
        );

      // Get all project entries.
        $projects = $harvest->getProjects(Carbon::parse($from)->toDateTimeString());
        $output->writeln('<info>Getting data for '.count($projects->get('data')).' projects</info>');
        $entries = [];

      // Get entries.
        foreach ($projects->get('data') as $project) {
            if (in_array($project->get('id'), $config['sync']['projects']['exclude'])) {
                $output->writeln('<comment>- Skipping project '.$project->get('name').', in exclude list</comment>');
                continue;
            }
            // In strict mode, only get time entries for project with a mapping.
            if ($input->getOption('strict') && !isset($config['sync']['projects']['map'][$project->get('id')])) {
                $output->writeln(
                    sprintf(
                        '<comment>- Skipping project %s (%d), it is not mapped to a Redmine project.</comment>',
                        $project->get('name'),
                        $project->get('id')
                    )
                );
                continue;
            }
            $output->writeln('<comment>- Retrieving time entry data for '.$project->get('name').'</comment>');
            $project_entries = $harvest->getProjectEntries($project->get('id'), $range);
            foreach ($project_entries->get('data') as $entry) {
                $entries[] = $entry;
            }
        }

        $entries_to_log = [];
        $entries_without_id = [];

        foreach ($entries as $entry) {
            if ($entry->get('billable') == false) {
                // We only care about billable time.
                continue;
            }
            if (strpos($entry->get('notes'), '#') === false) {
                $entries_without_id[] = $entry;
            } else {
                $entries_to_log[] = $entry;
            }
        }

        $output->writeln(
            sprintf(
                '<info>Found %d entries with possible Redmine IDs and %d without</info>',
                count($entries_to_log),
                count($entries_without_id)
            )
        );

      // Get all time entries from Redmine.
        $time_api = new Redmine\Api\TimeEntry($redmine_client);

        foreach ($entries_to_log as $entry) {
            $update = false;
            $update_id = null;
            $output->writeln(
                sprintf(
                    '<info>Processing entry: "%s" (%d) in project %s</info>',
                    $entry->get('notes'),
                    $entry->get('id'),
                    $config['sync']['projects']['map'][$entry->get('project-id')]
                )
            );
            // Load the Redmine issue and check if the Harvest time entry ID is there, if so, skip.
            $redmine_issue_numbers = [];
            preg_match('/#([0-9]+)/', $entry->get('notes'), $redmine_issue_numbers);
            // Strip the leading '#', and take the first entry.
            $redmine_issue_number = reset($redmine_issue_numbers);
            $redmine_issue_number = str_replace('#', '', $redmine_issue_number);
            $redmine_time_entries = $time_api->all(array(
            'issue_id' => $redmine_issue_number,
            'limit' => 10000,
            ));
            if (isset($redmine_time_entries['total_count']) && $redmine_time_entries['total_count'] > 0) {
                // There might be a match.
                foreach ($redmine_time_entries['time_entries'] as $rm_time_entry) {
                    if (strpos($rm_time_entry['comments'], $entry->get('id')) !== false) {
                        // There's a match, skip this entry.
                        if ($input->getOption('update')) {
                            $update = true;
                            $update_id = $rm_time_entry['id'];
                            // Break out of this loop and continue with processing the update.
                            break;
                        } else {
                            $output->writeln(
                                '<comment>- There is already a time entry for '.$entry->get('notes').'</comment>'
                            );
                            continue 2;
                        }
                    }
                }
            }

            $issue_api = new Redmine\Api\Issue($redmine_client);
            $redmine_issue = $issue_api->show($redmine_issue_number);

            if (!$update) {
                // If we are creating a new entry, verify that it can be created.
                if (!$redmine_issue || !isset($redmine_issue['issue']['project']['id'])) {
                    // Issue doesn't exist in Redmine; this is probably a GitHub issue reference.
                    $output->writeln(
                        sprintf(
                            '<error>- Could not find Redmine issue %d!</error>',
                            $redmine_issue_number
                        )
                    );
                    continue;
                }

                // Validate that issue ID exists in project.
                if (isset($config['sync']['projects']['map'][$entry->get('project-id')])
                && $config['sync']['projects']['map'][$entry->get('project-id')]
                !== $redmine_issue['issue']['project']['name']
                ) {
                    // The issue number doesn't belong to the Harvest project we are looking at
                    // time entries for, so continue. It's probably a GitHub issue ref.
                    $output->writeln(
                        sprintf(
                            '<comment>- Skipping entry for %d as it is out of range!</comment>',
                            $entry->get('id')
                        )
                    );
                    continue;
                }
            }

            if (!isset($config['sync']['users'][$entry->get('user-id')])) {
                // No mapping is defined in the config, so throw an error and skip this entry.
                $output->writeln(
                    sprintf(
                        '<error>No mapping is defined for user %d, please adjust config.yaml</error>',
                        $entry->get('user-id')
                    )
                );
                continue;
            }

            // We can log this entry.
            // Round the hours to the nearest .25 to simulate what Harvest does.
            $hours = round($entry->get('hours') / .25, 0) * 0.25;
            $params = array(
            'issue_id' => $redmine_issue_number,
             // Default to 'development'.
            'spent_on' => $entry->get('spent-at'),
            'activity_id' => 9,
            'project_id' => $redmine_issue['issue']['project']['id'],
            'hours' => $hours,
            'comments' => $entry->get('notes').' [Harvest ID #'.$entry->get('id').']',
            );

            try {
                $redmine_user = new Redmine\Client(
                    $config['auth']['redmine']['url'],
                    $config['auth']['redmine']['user'],
                    $config['auth']['redmine']['pass']
                );
                $redmine_user->setImpersonateUser($config['sync']['users'][$entry->get('user-id')]);
                if (!$input->getOption('dry-run')) {
                    $time_api = new Redmine\Api\TimeEntry($redmine_user);
                    if (!$update) {
                        $time_api->create($params);
                    } else {
                        // Update existing entry.
                        $time_api->update($update_id, $params);
                    }
                }
                $op = ($update) ? 'Updated' : 'Created';
                $output->writeln(
                    sprintf(
                        '<comment>'.$op.' time entry for issue #%d with %s hours</comment>',
                        $redmine_issue_number,
                        $hours
                    )
                );
            } catch (Exception $e) {
                $output->writeln(
                    sprintf(
                        '<comment>Failed to create time entry for issue #%d!</comment>',
                        $redmine_issue_number,
                        $e->getMessage()
                    )
                );
            }
        }

        $output->writeln('<question>All done!</question>');
      });

$console->run();
