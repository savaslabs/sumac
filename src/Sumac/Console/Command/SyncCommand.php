<?php

namespace Sumac\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;
use Redmine;
use Harvest\HarvestAPI;
use Harvest\Model\Range;
use Carbon\Carbon;

class SyncCommand extends Command
{
    /** @var  \Harvest\Model\Range */
    private $range;
    private $input;
    private $output;
    private $config;
    /** @var  \Redmine\Client */
    private $redmineClient;
    /** @var  \Harvest\HarvestAPI */
    private $harvestClient;

    /** @var array
     * Maps harvest IDs to Redmine IDs.
     */
    protected $projectMap;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('sync')
          ->setDefinition(
              [
              new InputArgument(
                  'date',
                  InputArgument::OPTIONAL,
                  'Date to sync data for. Defaults to current day.',
                  Carbon::create()->format('Ymd')
              ),
              new InputOption('update', 'u', null, 'Update existing time entries.'),
              new InputOption('strict', 's', null, 'Require project map to be defined.'),
              new InputOption('dry-run', 'd', null, 'Do a simulation of what would happen'),
              ]
          )
          ->setDescription('Pushes time entries from Harvest to Redmine');
    }

    /**
     * Set the Harvest Range based on the 'date' argument.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     */
    private function setRange(InputInterface $input)
    {
        $range = $input->getArgument('date');
        if (strpos($range, ':') !== false) {
            list($from, $to) = explode(':', $range);
        } else {
            $to = $from = $range;
        }
        $this->range = new Range($from, $to);
    }

    /**
     * Get the date range for the sync query.
     *
     * @return \Harvest\Model\Range;
     */
    private function getRange()
    {
        return $this->range;
    }

    /**
     * Set configuration from config.yml.
     */
    private function setConfig()
    {
        // Load the configuration.
        $yaml = new Yaml();
        if (!file_exists('config.yml')) {
            throw new \Exception('Could not find a config.yml file.');
        }
        try {
            $this->config = $yaml->parse(file_get_contents('config.yml'), true);
        } catch (\Exception $e) {
            $this->output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
        }
    }

    /**
     * Set a Harvest client for later use.
     */
    private function setHarvestClient($config)
    {
        $this->harvestClient = new HarvestAPI();
        $this->harvestClient->setUser($config['auth']['harvest']['mail']);
        $this->harvestClient->setPassword($config['auth']['harvest']['pass']);
        $this->harvestClient->setAccount($config['auth']['harvest']['account']);
    }

    /**
     * Set a Redmine client for later use.
     */
    private function setRedmineClient($config)
    {
        $this->redmineClient = new Redmine\Client(
            $config['auth']['redmine']['url'],
            $config['auth']['redmine']['user'],
            $config['auth']['redmine']['pass']
        );
    }

    /**
     * Pull projects from Redmine and populate redmine/harvest map.
     */
    protected function populateProjectMap(OutputInterface $output)
    {
        $this->projectMap = array();

        if (!$this->redmineClient) {
            $this->setRedmineClient($this->config);
        }

        $projects = $this->redmineClient->project->all();
        foreach ($projects['projects'] as $project) {
            foreach ($project['custom_fields'] as $custom_field) {
                if ($custom_field['name'] == 'Harvest Project ID' && !empty($custom_field['value'])) {
                    $project_ids = explode(',', $custom_field['value']);
                    foreach ($project_ids as $project_id) {
                        $this->projectMap[trim($project_id)] = $project['id'];
                    }
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Set input/output for use in other methods.
        $this->input = $input;
        $this->output = $output;

        // Set the Harvest Range.
        $this->setRange($input);
        $output->writeln('<question>Syncing data for time period between '.
            $this->getRange()->from().
            ' and '.
            $this->getRange()->to().'</question>');

        // Load configuration.
        $this->setConfig();

        // Initialize the Harvest client.
        $this->setHarvestClient($this->config);

        // Initialize the Redmine client.
        $this->setRedmineClient($this->config);

        $this->populateProjectMap($output);

        // Get all Harvest project entries.
        // n.b. since `updated_since` no longer works as an argument for the
        // Harvest REST API, we need to manually filter these by date range
        // further below.
        /** @var \Harvest\Model\Result $projects */
        $projects = $this->harvestClient->getProjects();

        $output->writeln('<info>Getting data for '.count($projects->get('data')).' projects</info>');
        $entries = [];

        // Get entries.
        /** @var $project \Harvest\Model\Project */
        foreach ($projects->get('data') as $project) {
            if (in_array($project->get('id'), $this->config['sync']['projects']['exclude'])) {
                $output->writeln('<comment>- Skipping project '.$project->get('name').', in exclude list</comment>');
                continue;
            }

            // In strict mode, only get time entries for project with a mapping.
            if ($input->getOption('strict') && !isset($this->projectMap[$project->get('id')])) {
                $output->writeln(
                    sprintf(
                        '<comment>- Skipping project %s (%d), it is not mapped to a Redmine project.</comment>',
                        $project->get('name'),
                        $project->get('id')
                    )
                );
                continue;
            }

            $output->writeln('<comment>- Retrieving time entry data for '
              .$project->get('name')
              .' ('.$project->get('id')
              .')</comment>');
            $project_entries = $this->harvestClient->getProjectEntries($project->get('id'), $this->getRange());
            foreach ($project_entries->get('data') as $entry) {
                $entries[] = $entry;
            }
        }

        $entries_to_log = [];
        $entries_without_id = [];

        foreach ($entries as $entry) {
            // TODO: We used to filter by billable time, but the API changed and that's no longer available to us.
            // @see https://github.com/harvesthq/api/issues/153
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
        $time_api = new Redmine\Api\TimeEntry($this->redmineClient);

        foreach ($entries_to_log as $entry) {
            $update = false;
            $update_id = null;
            $output->writeln(
                sprintf(
                    '<info>Processing entry: "%s" (%d) in Redmine project %s</info>',
                    $entry->get('notes'),
                    $entry->get('id'),
                    isset($this->projectMap[$entry->get('project-id')]) ?
                      $this->projectMap[$entry->get('project-id')] : 'unknown'
                )
            );

            // Load the Redmine issue and check if the Harvest time entry ID is there, if so, skip.
            $redmine_issue_numbers = [];
            preg_match('/#([0-9]+)/', $entry->get('notes'), $redmine_issue_numbers);
            // Strip the leading '#', and take the first entry.
            $redmine_issue_number = reset($redmine_issue_numbers);
            $redmine_issue_number = str_replace('#', '', $redmine_issue_number);

            $redmine_search_params = array(
              'issue_id' => $redmine_issue_number,
              'limit' => 10000,
            );

            $redmine_time_entries = $time_api->all($redmine_search_params);

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
                                '<comment>- There is already a time entry for '
                                .$entry->get('notes')
                                .'</comment>'
                            );
                            continue 2;
                        }
                    }
                }
            }

            $issue_api = new Redmine\Api\Issue($this->redmineClient);
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
                if (isset($this->projectMap[$entry->get('project-id')])
                  && $this->projectMap[$entry->get('project-id')]
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

            if (!isset($this->config['sync']['users'][$entry->get('user-id')])) {
                // No mapping is defined in the config, so throw an error and skip this entry.
                $output->writeln(
                    sprintf(
                        '<error>No mapping is defined for user %d, please adjust config.yml</error>',
                        $entry->get('user-id')
                    )
                );
                continue;
            }

            // We can log this entry.
            // Round the hours up to the nearest .25 to simulate what Harvest does.
            $hours = ceil(4 * floatval($entry->get('hours'))) / 4;

            $params = [
              'issue_id' => $redmine_issue_number,
                // Default to 'development'.
              'spent_on' => $entry->get('spent-at'),
              'activity_id' => 9,
              'project_id' => $redmine_issue['issue']['project']['id'],
              'hours' => $hours,
              'comments' => $entry->get('notes').' [Harvest ID: '.$entry->get('id').']',
            ];

            try {
                $redmine_user = new Redmine\Client(
                    $this->config['auth']['redmine']['url'],
                    $this->config['auth']['redmine']['user'],
                    $this->config['auth']['redmine']['pass']
                );
                $redmine_user->setImpersonateUser($this->config['sync']['users'][$entry->get('user-id')]);
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
                        '<comment>'.$op.' time entry for issue #%d with %s hours (Harvest hours: %s)</comment>',
                        $redmine_issue_number,
                        $hours,
                        $entry->get('hours')
                    )
                );
            } catch (\Exception $e) {
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
    }
}
