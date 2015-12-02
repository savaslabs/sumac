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
  ->setDefinition(array(
    new InputArgument('date', InputArgument::REQUIRED, 'Date to sync data for'),
  ))
  ->setDescription('Pushes time entries from Harvest to Redmine')
  ->setCode(function (InputInterface $input, OutputInterface $output) {
    $range = $input->getArgument('date');
    // TODO: Validate the range.
    if (strpos($range, ':') !== FALSE) {
      list($from, $to) = explode(':', $range);
    }
    else {
      $to = $from = $range;
    }
    $range = new Range($from, $to);
    $output->writeln('Syncing data for time period between ' . $from . ' and ' . $to);
    $harvest = new HarvestAPI();
    $yaml = new Yaml();
    $config = $yaml->parse(file_get_contents('config.yml'));
    if (!$config) {
      return;
    }
    $harvest->setUser($config['auth']['harvest']['mail']);
    $harvest->setPassword($config['auth']['harvest']['pass']);
    $harvest->setAccount($config['auth']['harvest']['account']);
    $redmine_client = new Redmine\Client($config['auth']['redmine']['url'], $config['auth']['redmine']['user'], $config['auth']['redmine']['pass']);

// Get all project entries.
    $projects = $harvest->getProjects(Carbon::parse($from)->toDateTimeString());
    // TODO: Allow config option to exclude some projects.
    $output->writeln('<info>Getting data for ' . count($projects->get('data')) . ' projects</info>');
    $entries = [];

    // Get entries.
    foreach ($projects->get('data') as $project) {
      if (in_array($project->get('id'), $config['sync']['projects']['exclude'])) {
        $output->writeln('- Skipping project ' . $project->get('name') . ', in exclude list');
        continue;
      }
      $output->writeln('- Retrieving time entry data for ' . $project->get('name'));
      $project_entries = $harvest->getProjectEntries($project->get('id'), $range);
      foreach ($project_entries->get('data') as $entry) {
        $entries[] = $entry;
      }
    }
// TODO: Filter billable/non-billable time.
    $entries_to_log = [];
    $entries_without_id = [];

    foreach ($entries as $entry) {
      if (strpos($entry->get('notes'), '#') === FALSE) {
        $entries_without_id[] = $entry;
      }
      else {
        $entries_to_log[] = $entry;
      }
    }

    $output->writeln(sprintf('<info>Found %d entries with possible Redmine IDs and %d without</info>', count($entries_to_log), count($entries_without_id)));

// Get all time entries from Redmine.
    $time_api = new Redmine\Api\TimeEntry($redmine_client);

    foreach ($entries_to_log as $entry) {
      $output->writeln(sprintf("<info>Processing entry %d - %s</info>", $entry->get('id'), $entry->get('notes')));
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
          if (strpos($rm_time_entry['comments'], $entry->get('id')) !== FALSE) {
            // There's a match, skip this entry.
            $output->writeln('<comment>- There is already a time entry for ' . $entry->get('notes') . '</comment>');
            continue 2;
          }
        }
      }

      // Validate that issue exists in project.
      $issue_api = new Redmine\Api\Issue($redmine_client);
      $redmine_issue = $issue_api->show($redmine_issue_number);
      if (!$redmine_issue || !isset($redmine_issue['issue']['project']['id'])) {
        // Issue doesn't exist; this is probably a GitHub issue reference.
        $output->writeln(sprintf('<error>- Could not find Redmine issue %d!</error>', $redmine_issue_number));
        continue;
      }
      // TODO: Get project name dynamically, by looking up a sync value in the
      // config.
      if (isset($config['sync']['projects']['map'][$entry->get('project-id')]) && $config['sync']['projects']['map'][$entry->get('project-id')] !== $redmine_issue['issue']['project']['name']) {
        // The issue number doesn't belong to the Harvest project we are looking at
        // time entries for, so continue. It's probably a GitHub issue ref.
        $output->writeln(sprintf("<comment>- Skipping entry for %d as it is out of range!</comment>", $entry->get('id')));
        continue;
      }

      // We can log this entry.
      // TODO: Set Activity ID.
      // TODO: Set author.
      // TODO: Set spent_on.
      $params = array(
        'issue_id' => $redmine_issue_number,
        'activity_id' => 9,
        'project_id' => $redmine_issue['issue']['project']['id'],
        'hours' => $entry->get('hours'),
        'comments' => $entry->get('notes') . ' [Harvest ID #' . $entry->get('id') . ']',
      );
      try {
        $time_api->create($params);
        $output->writeln(sprintf('Created new time entry for issue #%d with hours %s', $redmine_issue_number, $entry->get('hours')));
      }
      catch (Exception $e) {
        $output->writeln(sprintf('Failed to create time entry for issue #%d!', $redmine_issue_number, $e->getMessage()));
      }
    }

    $output->writeln('Done!');
  })
;

$console->run();
