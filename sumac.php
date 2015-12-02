#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';

$harvest = new \Harvest\HarvestAPI();
$yaml = new \Symfony\Component\Yaml\Yaml();
$config = $yaml->parse(file_get_contents('config.yml'));
if (!$config) {
  return;
}
$harvest->setUser($config['auth']['harvest']['mail']);
$harvest->setPassword($config['auth']['harvest']['pass']);
$harvest->setAccount($config['auth']['harvest']['account']);

$fromDate = new DateTime();
$fromDate->setDate(2015, 11, 1);
$from = $fromDate->format('Ymd');
$toDate = new DateTime();
$toDate->setDate(2015, 12, 1);
$to = $toDate->format('Ymd');
$range = new \Harvest\Model\Range($from, $to);
// TODO: Get all project entries.
$entries = $harvest->getProjectEntries(8285013, $range);

// Get entries.
// TODO: Filter billable/non-billable time.
$entries_to_log = [];
$entries_without_id = [];

foreach ($entries->get('data') as $entry) {
  if (strpos($entry->get('notes'), '#') === FALSE) {
    $entries_without_id[] = $entry;
  }
  else {
    $entries_to_log[] = $entry;
  }
}

printf("Found %d entries with Redmine IDs and %d without\n", count($entries_to_log), count($entries_without_id));

// Further filter entries to log by checking against Redmine.
$redmine_client = new Redmine\Client($config['auth']['redmine']['url'], $config['auth']['redmine']['user'], $config['auth']['redmine']['pass']);
$entries_already_logged = [];

// Get all time entries from Redmine.
$time_api = new Redmine\Api\TimeEntry($redmine_client);

foreach ($entries_to_log as $entry) {
  printf("Processing entry %d - %s\n", $entry->get('id'), $entry->get('notes'));
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
    $redmine_time_entry_messages = [];
    foreach ($redmine_time_entries['time_entries'] as $rm_time_entry) {
      if (strpos($rm_time_entry['comments'], $entry->get('id')) !== FALSE) {
        // There's a match, skip this entry.
        continue 2;
      }
    }
  }

  // Validate that issue exists in project.
  $issue_api = new Redmine\Api\Issue($redmine_client);
  $redmine_issue = $issue_api->show($redmine_issue_number);
  if (!$redmine_issue || !isset($redmine_issue['issue']['project']['id'])) {
    // Issue doesn't exist; this is probably a GitHub issue reference.
    printf('> Could not find Redmine issue %d!', $redmine_issue_number);
    continue;
  }
  // TODO: Get project name dynamically.
  if ($redmine_issue['issue']['project']['id'] !== 10) {
    // The issue number doesn't belong to the Harvest project we are looking at
    // time entries for, so continue. It's probably a GitHub issue ref.
    printf("> Skipping entry for %d as it is out of range!", $entry->get('id'));
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
    printf("Created new time entry for issue #%d with hours %s\n", $redmine_issue_number, $entry->get('hours'));
  }
  catch (Exception $e) {
    printf("Failed to create time entry for issue #%d!", $redmine_issue_number, $e->getMessage());
  }
  print "\n";
}

print 'Done!';