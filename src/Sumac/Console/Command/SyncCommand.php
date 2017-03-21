<?php

namespace Sumac\Console\Command;

use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;
use Redmine;
use Harvest\HarvestAPI;
use Harvest\Model\Range;
use Harvest\Model\DayEntry;
use Carbon\Carbon;

class SyncCommand extends Command
{
    /** @var \Harvest\Model\Range */
    private $range;
    /** @var \Symfony\Component\Console\Input\InputInterface */
    private $input;
    /** @var \Symfony\Component\Console\Output\OutputInterface */
    private $output;
    /** @var \Symfony\Component\Console\Style\SymfonyStyle */
    private $io;
    /** @var array */
    private $config;
    /** @var \Redmine\Client */
    private $redmineClient;
    /** @var \Harvest\HarvestAPI */
    private $harvestClient;
    /** @var array */
    private $redmineTimeEntries;
    /** @var int
     * Custom field ID for the Harvest Time Entry ID field
     * on Redmine time entries
     */
    private $harvestTimeEntryFieldId = 20;
    /** @var int
     * Custom field ID for the Project Manager ID field on Redmine projects
     */
    private $redmineProjectManagerFieldId = 21;
    /** @var int
     * Custom field ID for the Remaining Time field on Redmine issues
     */
    private $remainingTimeFieldId = 23;
    /** @var array */
    private $syncErrors = array();
    /** @var array */
    private $syncSuccesses;
    /** @var array
     * Stores which projects to load Harvest & Redmine data for when debugging
     */
    protected $debugProjects = array();
    /** @var array
     * Maps harvest IDs to Redmine IDs
     */
    protected $projectMap;

    /** @var array
     * Maps Redmine users to Harvest IDs
     */
    protected $userMap;

    /** @var array */
    protected $syncedHarvestRecords = array();

    /** @var array */
    protected $cachedHarvestEntries = array();

    /** @var array
     * Maps Redmine Project IDs to Redmine "Project management" issue arrays
     */
    protected $redmineProjectsToPmIssuesMap = array();

    /** @var array
     * Maps Harvest IDs to Slack usernames
     */
    protected $slackUserMap;

    /** @var array
     * Store error notifications by Harvest user ID
     */
    protected $userTimeEntryErrors;

    /** @var int
     * Store PSpell dictionary identifier so we don't have to reload each time
     */
    protected $pspellLink;

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
                    new InputOption(
                        'update',
                        'u',
                        null,
                        'Update existing time entries.'
                    ),
                    new InputOption(
                        'dry-run',
                        'd',
                        null,
                        'Do a simulation of what would happen.'
                    ),
                    new InputOption(
                        'log-slack-notifications',
                        null,
                        null,
                        'Log all Slack notifications to stdout.'
                    ),
                    new InputOption(
                        'config',
                        'c',
                        InputOption::VALUE_OPTIONAL,
                        'Path to configuration file. Leave empty if config.yml is in repository root.'
                    ),
                    new InputOption(
                        'slack-notify',
                        null,
                        null,
                        'If set, will attempt to send Slack notifications to users about errors in their time entries.'
                    ),
                ]
            )
            ->setDescription('Pushes time entries from Harvest to Redmine');

        $this->userTimeEntryErrors = array();
    }

    /**
     * Set up custom PSpell dictionary.
     */
    private function configurePSpell()
    {
        // Retrieve Redmine spelling dictionary wiki path.
        if (isset($this->config['spellcheck']['project_name']) &&
          isset($this->config['spellcheck']['wiki_page_name'])) {
            $wiki_project_name = $this->config['spellcheck']['project_name'];
            $wiki_page_name = $this->config['spellcheck']['wiki_page_name'];
        } else {
            // Exit and log a warning if the wiki path variables are not set.
            $this->io->warning(
                sprintf(
                    'Redmine dictionary wiki location not properly set in config.yml (see config.example.yml).'
                )
            );

            return;
        }

        // Load the wiki.
        $wikiObject = new Redmine\Api\Wiki($this->redmineClient);
        $wiki_page = $wikiObject->show($wiki_project_name, $wiki_page_name);

        // Check if the wiki page text was found and is a string.
        if (!is_string($wiki_page['wiki_page']['text'])) {
            // Log a warning that the wiki wasn't loaded.
            $this->io->warning(
                sprintf(
                    "Unable to load spelling dictionary wiki from Redmine using project name '%s' and wiki name '%s'.",
                    $wiki_project_name,
                    $wiki_page_name
                )
            );

            return;
        }

        // Populate words to ignore. The Redmine wiki uses "\r\n" for new lines.
        $words_to_ignore = explode("\r\n", $wiki_page['wiki_page']['text']);

        // Check that $words_to_ignore is an array.
        if (!is_array($words_to_ignore)) {
            return;
        }

        // Sort the items by alphabetical order and update the wiki page.
        $title = array_shift($words_to_ignore);
        $header = array_shift($words_to_ignore);
        natcasesort($words_to_ignore);
        $sorted_data = implode("\r\n", $words_to_ignore);
        $sorted_text = $title."\r\n".$header."\r\n".$sorted_data;
        $wikiObject->update($wiki_project_name, $wiki_page_name, ['text' => $sorted_text]);

        $this->pspellLink = pspell_new('en');
        foreach ($words_to_ignore as $word) {
            pspell_add_to_session($this->pspellLink, $word);
        }
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
        if ($config_path = $this->input->getOption('config')) {
            if (!file_exists($config_path)) {
                throw new \Exception(sprintf('Could not find the config.yml file at %s', $config_path));
            }
        } else {
            $config_path = 'config.yml';
        }

        // Load the configuration.
        $yaml = new Yaml();
        if (!file_exists($config_path)) {
            throw new \Exception('Could not find a config.yml file.');
        }
        try {
            $this->config = $yaml->parse(file_get_contents($config_path), true);
        } catch (\Exception $e) {
            $this->output->writeln(
                sprintf(
                    '<error>%s</error>',
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Set a Harvest client for later use.
     */
    private function setHarvestClient()
    {
        $this->harvestClient = new HarvestAPI();
        $this->harvestClient->setUser($this->config['auth']['harvest']['mail']);
        $this->harvestClient->setPassword($this->config['auth']['harvest']['pass']);
        $this->harvestClient->setAccount($this->config['auth']['harvest']['account']);
    }

    /**
     * Set a Redmine client for later use.
     */
    private function setRedmineClient()
    {
        $this->redmineClient = new Redmine\Client(
            $this->config['auth']['redmine']['url'],
            $this->config['auth']['redmine']['apikey']
        );
    }

    /**
     * Cache all Redmine time entries in a local array.
     */
    private function cacheRedmineTimeEntries()
    {
        // Re-initialize the Redmine client.
        $this->setRedmineClient();

        // When debugging, limit Redmine time entry caching to Redmine projects
        // associated with the Harvest projects specified in the config.
        if (!empty($this->debugProjects)) {
            $all_time_entries = $this->getDebugProjectsTimeEntries();
        } else {
            $all_time_entries = $this->redmineClient->time_entry->all(array(
              'limit' => 1000000,
              'offset' => 0,
            ));
        }

        if (!isset($all_time_entries['time_entries'])) {
            $this->output->writeln(
                '<error>Invalid time entry list returned from API.'
                .' Possible that API token is not set correctly.</error>'
            );

            return false;
        }

        // Filter out time entries with null Harvest Time Entry ID and rework
        // this into a dictionary by harvest ID.
        $this->redmineTimeEntries = array();

        foreach ($all_time_entries['time_entries'] as $time_entry) {
            if (is_array($time_entry['custom_fields'])) {
                foreach ($time_entry['custom_fields'] as $field) {
                    if ($field['name'] == 'Harvest Time Entry ID' && !empty($field['value'])) {
                        if (isset($this->redmineTimeEntries[$field['value']])) {
                            $this->output->writeln(
                                '<error>Duplicate Redmine time entries found with Harvest ID %s</error>',
                                $field['value']
                            );

                            return false;
                        } else {
                            $this->redmineTimeEntries[$field['value']] = $time_entry;
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns Redmine time entries limited to Redmine projects associated with
     * the Harvest projects specified in debugProjects.
     *
     * @return array
     */
    private function getDebugProjectsTimeEntries()
    {
        $all_time_entries = array();
        $fetched_projects = array();
        foreach ($this->debugProjects as $harvest_id => $redmine_projects) {
            foreach ($redmine_projects as $project_id => $project_name) {
                if (!in_array($project_id, $fetched_projects)) {
                    $project_time_entries = $this->redmineClient->time_entry->all(array(
                      'limit' => 1000000,
                      'offset' => 0,
                      'project_id' => $project_id,
                    ));
                    if (isset($project_time_entries['time_entries'])) {
                        if (!isset($all_time_entries['time_entries'])) {
                            $all_time_entries['time_entries'] = $project_time_entries['time_entries'];
                        } else {
                            $all_time_entries['time_entries'] = array_merge($all_time_entries['time_entries'], $project_time_entries['time_entries']);
                        }
                    }
                    array_push($fetched_projects, $project_id);
                }
            }
        }

        return $all_time_entries;
    }

    /**
     * Logs errors to slack for a given harvest user.
     *
     * @param int   $harvest_id
     *                          The Harvest ID of the user to message about incorrect entries
     * @param array $errors
     *                          An array of time entry errors to send to Slack
     *
     * @return none
     */
    protected function logErrorsToSlack($harvest_id, array $errors)
    {
        if (isset($this->config['auth']['slack']['debug-user'])) {
            $slack_id = $this->config['auth']['slack']['debug-user'];
        } else {
            $slack_id = $this->slackUserMap[$harvest_id];
        }
        if (empty($slack_id)) {
            $this->output->writeln(
                sprintf(
                    '<warning>Slack user not defined for Harvest user %s.</warning>',
                    $harvest_id
                )
            );

            return;
        }
        if (isset($this->config['auth']['slack']['webhook_url'])) {
            $slack_url = $this->config['auth']['slack']['webhook_url'];
        } else {
            $this->output->writeln(
                '<warning>Slack webhook URL not configured. Errors will not be logged to slack.</warning>'
            );

            return;
        }

        $error_category_titles = [
            'unable-to-sync' => 'For reasons unknown this time entry would not sync.',
            'harvest-id-not-synced' => 'API call to Redmine failed.',
            'no-issue-number' => 'Could not locate a default "Project management" issue.',
            'entry-logged-to-pm-issue' => 'Hi PM! FYI, these time entries were logged to default PM issue',
            'missing-issue' => 'Time entries where no matching redmine issue was found',
            'issue-not-in-project' => 'Redmine issue\'s project doesn\'t match up with the Harvest project.',
            'spelling' => 'Possible spelling errors',
        ];

        $error_message_formatters = [
            'unable-to-sync' => function ($error) {
                return sprintf(
                    "%s -- %s\n%s",
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['entry']->get('notes'),
                    $this->getClickableTimeEntryUrl($error['entry'])
                );
            },
            'harvest-id-not-synced' => function ($error) {
                return sprintf(
                    "%s -- %s\n%s",
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['entry']->get('notes'),
                    $this->getClickableTimeEntryUrl($error['entry'])
                );
            },
            'entry-logged-to-pm-issue' => function ($error) {
                return sprintf(
                    "%s (%s) -- %s\n%s",
                    substr($error['entry']->get('spent-at'), 0, 10),
                    sprintf('Logged by %s in %s', $error['team-member'], $error['project']),
                    $error['entry']->get('notes'),
                    $this->getClickableTimeEntryUrl($error['entry'])
                );
            },
            'no-issue-number' => function ($error) {
                return sprintf(
                    "%s (%s) -- %s\n%s",
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['entry']->get('project-id'),
                    $error['entry']->get('notes'),
                    $this->getClickableTimeEntryUrl($error['entry'])
                );
            },
            'missing-issue' => function ($error) {
                return sprintf(
                    "%s -- %s\n%s",
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['entry']->get('notes'),
                    $this->getClickableTimeEntryUrl($error['entry'])
                );
            },
            'issue-not-in-project' => function ($error) {
                return sprintf(
                    "%s -- %s\n%s",
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['notes'],
                    $this->getClickableTimeEntryUrl($error['entry'])
                );
            },
            'spelling' => function ($error) {
                return sprintf(
                    "Potential misspellings: _%s_\n%s",
                    implode(', ', $error['spelling-errors']),
                    $this->getClickableTimeEntryUrl($error['entry'])
                );
            },
        ];

        $fields = [];
        foreach ($errors as $category => $errors_array) {
            // Sort errors by date.
            usort(
                $errors_array,
                function ($a, $b) {
                    $a_date = new \DateTime($a['entry']->get('spent-at'));
                    $b_date = new \DateTime($b['entry']->get('spent-at'));
                    if ($a_date < $b_date) {
                        return -1;
                    } elseif ($b_date < $a_date) {
                        return 1;
                    }

                    return 0;
                }
            );

            $fields[] = [
                'title' => $error_category_titles[$category],
                'value' => implode("\n", array_map($error_message_formatters[$category], $errors_array)),
            ];
        }

        // Set time of day so slackbot can be a bit more conversational.
        $hour = date('H', time());
        $greeting = 'Sumac never sleeps';
        if ($hour > 6 && $hour <= 11) {
            $greeting = 'Good morning';
        } elseif ($hour > 11 && $hour <= 16) {
            $greeting = 'Good afternoon';
        } elseif ($hour > 16 && $hour <= 23) {
            $greeting = 'Good evening';
        }

        $client = new \GuzzleHttp\Client();
        $res = $client->request('POST', $slack_url, [
            'body' => json_encode([
                'username' => 'Sumac',
                'channel' => $slack_id,
                'text' => sprintf(
                    '%s %s! Here is a list of potential errors detected in your harvest time entries.',
                    $greeting,
                    $slack_id
                ),
                'attachments' => [
                    [
                        'fallback' => 'Unfortunately your client can not display this attachment.',
                        'color' => 'warning',
                        'fields' => $fields,
                        'mrkdwn_in' => ['fields'],
                    ],
                ],
            ]),
        ]);

        // Log all Slack messages to stdout.
        if ($this->input->getOption('log-slack-notifications') && is_array($fields)) {
            $this->io->section(sprintf('Slack notifications to %s', $this->userMap[$harvest_id]['name']));
            foreach ($fields as $stmt) {
                $this->io->note(
                    sprintf(
                        '%s %s',
                        $stmt['title'],
                        $stmt['value']
                    )
                );
            }
        }
    }

    /**
     * Pull projects from Redmine and populate redmine/harvest map.
     */
    protected function populateProjectMap()
    {
        $this->projectMap = [];

        $this->setRedmineClient();
        $projects = $this->redmineClient->project->all(['limit' => 1000]);
        if (!isset($projects['projects'])) {
            $this->output->writeln(
                '<error>Invalid project list returned from API. Possible that API token is not set correctly.</error>'
            );
        } else {
            foreach ($projects['projects'] as $project) {
                foreach ($project['custom_fields'] as $custom_field) {
                    if ($custom_field['name'] == 'Harvest Project ID(s)' && !empty($custom_field['value'])) {
                        $project_ids = explode(',', $custom_field['value']);
                        $project_id = trim(current($project_ids));
                        // If the identifier is null a.k.a. "-", then continue.
                        if ($project_id == '-') {
                            continue;
                        }
                        $this->projectMap[$project_id] = [
                            $project['id'] => $project['name'],
                        ];
                    }
                }
            }
        }

        // When debugging, limit project map to projects specified in config.
        if (is_array($this->config['sync']['projects']['debug_projects'])) {
            foreach ($this->projectMap as $harvest_id => $redmine_projects) {
                if (in_array($harvest_id, $this->config['sync']['projects']['debug_projects'])) {
                    $this->debugProjects[$harvest_id] = $redmine_projects;
                }
            }
            $this->projectMap = $this->debugProjects;
        }

        if (!count($this->projectMap)) {
            throw new Exception(('Unable to populate project map!'));
        }
    }

    /**
     * Get a map of Harvest IDs -> Redmine usernames.
     */
    protected function populateUserMap()
    {
        $this->userMap = [];
        $redmine_slack_user_map = [];
        $this->setRedmineClient();
        $active_users = $this->redmineClient->user->all(['limit' => 1000]);
        $locked_users = $this->redmineClient->user->all(['limit' => 1000, 'status' => 3]);
        $users = array_merge($active_users['users'], $locked_users['users']);
        foreach ($users as $user) {
            if (isset($user['custom_fields'])) {
                foreach ($user['custom_fields'] as $custom_field) {
                    if ($custom_field['name'] == 'Harvest ID' && !empty($custom_field['value'])) {
                        $this->userMap[trim($custom_field['value'])] = [
                            'name' => $user['login'],
                            'id' => $user['id'],
                        ];
                    }
                    if ($custom_field['name'] == 'Slack ID' && !empty($custom_field['value'])) {
                        $redmine_slack_user_map[$user['login']] = trim($custom_field['value']);
                    }
                }
            }
        }

        $redmine_harvest_map = [];
        foreach ($this->userMap as $harvest_id => $record) {
            $redmine_harvest_map[$record['name']] = $harvest_id;
        }
        foreach ($redmine_slack_user_map as $redmine_user => $slack_user) {
            $this->slackUserMap[$redmine_harvest_map[$redmine_user]] = $slack_user;
        }
        if (!count($this->userMap)) {
            throw new Exception('Unable to populate user map!');
        }
    }

    /**
     * Get all redmine time entries which might need to be synced.
     *
     * @param \Harvest\Model\Result $project
     *                                       Array of harvest projects
     *
     * @return array
     */
    protected function getHarvestTimeEntries($project)
    {
        $entries = [];
        // Get entries.
        /** @var $projects \Harvest\Model\Project */
        $project_data = $project->get('data');
        if (!is_object($project_data)) {
            // TODO: Better error message.
            throw new Exception('Unable to load project data');
        }

        $project_entries = $this->harvestClient->getProjectEntries(
            $project_data->get('id'),
            $this->getRange()
        );
        foreach ($project_entries->get('data') as $harvest_entry) {
            $entries[] = $harvest_entry;
        }

        return $entries;
    }

    /**
     * Get the "Project management" issue in a Redmine project for a Harvest entry.
     *
     * @param \Harvest\Model\DayEntry $entry
     *
     * @return array|bool
     *                    Array of Redmine issue information or false if no match found
     */
    protected function getRedmineProjectPmIssue(DayEntry $entry)
    {
        // Find project in map.
        $redmine_project = $this->projectMap[$entry->get('project-id')];
        $redmine_project_id = current(array_keys($redmine_project));
        if (isset($this->redmineProjectsToPmIssuesMap[$redmine_project_id])) {
            // If we already have a PM issue in our map, then return the issue early.
            // Add Slack notice.
            if (!empty($this->redmineProjectsToPmIssuesMap[$redmine_project_id]['pm-harvest-id'])) {
                $pm_harvest_user_id = $this->redmineProjectsToPmIssuesMap[$redmine_project_id]['pm-harvest-id'];
                $this->userTimeEntryErrors[$pm_harvest_user_id]['entry-logged-to-pm-issue'][] = [
                    'entry' => $entry,
                    'team-member' => $this->slackUserMap[$entry->get('user-id')],
                    'project' => $this->redmineProjectsToPmIssuesMap[$redmine_project_id]['project'],
                ];
            }
            return ['issue' => $this->redmineProjectsToPmIssuesMap[$redmine_project_id]['issue']];
        }
        // Show issues in project.
        $this->setRedmineClient();
        $issue_api = new Redmine\Api\Issue($this->redmineClient);

        $project_issues = $issue_api->all(
            [
                'project_id' => $redmine_project_id,
                'limit' => 10000,
            ]
        );

        // Look for an existing "Project management" issue.
        $pm_issue = current(array_filter($project_issues['issues'], function ($issue) {
            return $issue['subject'] == 'Project management';
        }));
        if ($pm_issue && count($pm_issue)) {
            // Ping PM that a time entry without # was filed here.
            // Get project manager user reference, and find their Slack ID.
            $project_api = new Redmine\Api\Project($this->redmineClient);
            $redmine_project_data = $project_api->show($redmine_project_id);
            $pm_id_key = array_search(
                $this->redmineProjectManagerFieldId,
                array_column($redmine_project_data['project']['custom_fields'], 'id')
            );
            $pm_harvest_user_id = null;
            if ($pm_id_key) {
                $pm_redmine_id = $redmine_project_data['project']['custom_fields'][$pm_id_key]['value'];
                $pm_harvest_user_key = array_search(
                    $pm_redmine_id,
                    array_column($this->userMap, 'id')
                );
                $pm_harvest_user_id = current(array_keys(array_slice($this->userMap, $pm_harvest_user_key, 1, true)));
                $this->userTimeEntryErrors[$pm_harvest_user_id]['entry-logged-to-pm-issue'][] = [
                    'entry' => $entry,
                    'team-member' => $this->slackUserMap[$entry->get('user-id')],
                    'project' => $redmine_project_data['project']['name']
                ];
            }

            $this->redmineProjectsToPmIssuesMap[$redmine_project_id] =
                [
                    'issue' => $pm_issue,
                    'pm-harvest-id' => $pm_harvest_user_id,
                    'project' => $redmine_project_data['project']['name'],
                ];

            return ['issue' => $pm_issue];
        }
        // Log an error to Sumac and to Slack user if PM Issue does not exist.
        $this->userTimeEntryErrors[$entry->get('user-id')]['no-issue-number'][] = [
            'entry' => $entry,
        ];
        $this->syncErrors[$entry->get('id')] = $this->formatError(
            'NO_PM_ISSUE_FOUND',
            $entry
        );

        return false;
    }

    /**
     * Get redmine issue matching this harvest entry and in the right project.
     *
     * @param \Harvest\Model\DayEntry $entry
     *
     * @return array|bool
     *                    Array of redmine issue information or FALSE if no match found
     */
    protected function getRedmineIssue(DayEntry $entry)
    {
        // Load the Redmine issue and check if the Harvest time entry ID is there, if so, skip.
        $redmine_issue_numbers = [];
        preg_match('/#([0-9]+)/', $entry->get('notes'), $redmine_issue_numbers);
        // Strip the leading '#', and take the first entry.
        $redmine_issue_number = reset($redmine_issue_numbers);
        $redmine_issue_number = str_replace('#', '', $redmine_issue_number);

        if (!$redmine_issue_number) {
            // If no issue number is in the message, tuck it away into the PM issue.
            return $this->getRedmineProjectPmIssue($entry);
        }

        $this->setRedmineClient();
        $issue_api = new Redmine\Api\Issue($this->redmineClient);
        $redmine_issue = $issue_api->show($redmine_issue_number);

        if (!$redmine_issue || !isset($redmine_issue['issue']['project']['id'])) {
            // Issue doesn't exist in Redmine; this is probably a GitHub issue reference.
            $this->userTimeEntryErrors[$entry->get('user-id')]['missing-issue'][] = [
                'entry' => $entry,
            ];

            $this->syncErrors[$entry->get('id')] = $this->formatError(
                'ISSUE_NOT_FOUND',
                $entry
            );

            return false;
        }

        // Validate that issue ID exists in possible projects.
        // Check if project ID exists in the map. If not, return false.
        if (!isset($this->projectMap[$entry->get('project-id')])) {
            // TODO: Slack error
            $this->syncErrors[$entry->get('id')] = $this->formatError(
                'REDMINE_PROJECT_DOESNT_EXIST',
                $entry
            );

            return false;
        }
        foreach ($this->projectMap[$entry->get('project-id')] as $project_id => $project_name) {
            if ($project_id == $redmine_issue['issue']['project']['id']) {
                // Found, return success.
                return $redmine_issue;
            }
        }
        // The issue number doesn't belong to the Harvest project we are looking at
        // time entries for, so log an error. It's either a GitHub issue reference, or an incorrect
        // issue number reference.
        $this->userTimeEntryErrors[$entry->get('user-id')]['issue-not-in-project'][] = [
            'entry' => $entry,
            'notes' => sprintf('Issue #%d does not exist in %s', $redmine_issue['issue']['id'], $project_name),
        ];
        $this->syncErrors[$entry->get('id')] = $this->formatError(
            'ISSUE_PROJECT_MISMATCH',
            $entry,
            sprintf('Issue #%d does not exist in %s', $redmine_issue['issue']['id'], $project_name)
        );

        return false;
    }

    /**
     * Get Redmine time entries matching a harvest entry.
     *
     * @param \Harvest\Model\DayEntry $harvest_entry
     *
     * @return array
     */
    protected function getExistingRedmineIssueTimeEntries(DayEntry $harvest_entry)
    {
        if (isset($this->redmineTimeEntries[$harvest_entry->get('id')])) {
            return $this->redmineTimeEntries[$harvest_entry->get('id')];
        } else {
            return false;
        }
    }

    /**
     * Populate the parameters for a Redmine time entry based on Harvest entry.
     *
     * @param array                   $redmine_issue
     * @param \Harvest\Model\DayEntry $harvest_entry
     *
     * @return array
     */
    protected function populateRedmineTimeEntry(
        array $redmine_issue,
        DayEntry $harvest_entry
    ) {
        $hours = ceil(4 * floatval($harvest_entry->get('hours'))) / 4;

        return [
            'issue_id' => $redmine_issue['issue']['id'],
            // Default to 'development'.
            'spent_on' => $harvest_entry->get('spent-at'),
            'activity_id' => 9,
            'project_id' => $redmine_issue['issue']['project']['id'],
            'hours' => $hours,
            'comments' => htmlspecialchars($harvest_entry->get('notes').' [Harvest ID: '.$harvest_entry->get('id').']'),
            'custom_fields' => [
                0 => [
                    'id' => $this->harvestTimeEntryFieldId,
                    'name' => 'Harvest Time Entry ID',
                    'value' => $harvest_entry->get('id'),
                ],
            ],
        ];
    }

    /**
     * Saves a harvest entry to Redmine, or updates an existing one if it exists.
     *
     * @param array       $redmine_time_entry_params
     * @param object|bool $existing_redmine_time_entry
     *
     * @return bool
     */
    protected function saveHarvestTimeEntryToRedmine(
        array $redmine_time_entry_params,
        $existing_redmine_time_entry
    ) {
        $time_entry_api = new Redmine\Api\TimeEntry($this->redmineClient);
        if ($existing_redmine_time_entry === false) {
            /** @var \SimpleXMLElement $result */
            $result = $time_entry_api->create($redmine_time_entry_params);
        } else {
            // Update existing entry.
            $time_entry_api->update(
                $existing_redmine_time_entry['id'],
                $redmine_time_entry_params
            );
            // Redmine API does not seem to return an object for PUT requests.
            $result = true;
        }
        // Keep a log of the Harvest IDs that we've synced.
        $harvest_id = $redmine_time_entry_params['custom_fields'][0]['value'];
        $this->syncedHarvestRecords[$harvest_id] = $harvest_id;

        // Update the "Remaining Time" field.
        if ($result) {
            // Re-initialize the Redmine client.
            $this->setRedmineClient();
            $issue_api = new Redmine\Api\Issue($this->redmineClient);
            $redmine_issue = $issue_api->show($redmine_time_entry_params['issue_id']);
            // Get index of the Remaining Time field.
            $remaining_time_key = array_search(
                $this->remainingTimeFieldId,
                array_column($redmine_issue['issue']['custom_fields'], 'id')
            );
            if ($remaining_time_key) {
                if (isset($redmine_issue['issue']['estimated_hours'])
                    && $redmine_issue['issue']['estimated_hours'] > 0) {
                    $estimated_hours = isset($redmine_issue['issue']['estimated_hours']) ?
                        $redmine_issue['issue']['estimated_hours'] : 0;
                    $spent_hours = isset($redmine_issue['issue']['spent_hours']) ?
                        $redmine_issue['issue']['spent_hours'] : 0;
                    $redmine_issue['issue']['custom_fields'][$remaining_time_key]['value'] =
                        $estimated_hours - $spent_hours;
                    // TODO: If estimated - spent = less than zero, ping PM via Slack.
                    $issue_api->update(
                        $redmine_time_entry_params['issue_id'],
                        [
                            'custom_fields' => $redmine_issue['issue']['custom_fields'],
                        ]
                    );
                }
            }
        }

        return ($result) ? $result : false;
    }

    /**
     * Spell check a single harvest time entry.
     *
     * @param \Harvest\Model\DayEntry $harvest_entry
     *
     * @return bool
     */
    protected function spellCheckEntry(DayEntry $harvest_entry)
    {
        // Check spelling.
        $words = explode(' ', preg_replace('/[^a-z]+/i', ' ', $harvest_entry->get('notes')));
        $spelling_errors = array();
        foreach ($words as $word) {
            if (!pspell_check($this->pspellLink, $word)) {
                $spelling_errors[] = $word;
            }
        }
        if ($spelling_errors) {
            $this->userTimeEntryErrors[$harvest_entry->get('user-id')]['spelling'][] = [
              'entry' => $harvest_entry,
              'spelling-errors' => $spelling_errors,
            ];
        }
    }

    /**
     * Sync a single harvest time entry.
     *
     * @param \Harvest\Model\DayEntry $harvest_entry
     *
     * @return bool
     */
    protected function syncEntry(DayEntry $harvest_entry)
    {
        $redmine_issue = $this->getRedmineIssue($harvest_entry);
        if (empty($redmine_issue['issue'])) {
            return false;
        }

        $existing_redmine_time_entry = $this->getExistingRedmineIssueTimeEntries(
            $harvest_entry
        );

        // If there are existing Redmine time entries matching this harvest entry and we are not updating, skip.
        if ($existing_redmine_time_entry !== false && !$this->input->getOption('update')) {
            return false;
        }

        // If Harvest user is not mapped to a redmine user, throw an error and continue.
        if (!isset($this->userMap[$harvest_entry->get('user-id')])) {
            $this->io->error(
                sprintf(
                    '<error>No mapping is defined for user %d</error>',
                    $harvest_entry->get('user-id')
                )
            );

            return false;
        }

        // Log the entry.
        $redmine_entry_params = $this->populateRedmineTimeEntry(
            $redmine_issue,
            $harvest_entry
        );

        $save_entry_result = false;
        $this->setRedmineClient();
        if (!$this->input->getOption('dry-run')) {
            try {
                $this->redmineClient->setImpersonateUser(
                    $this->userMap[$harvest_entry->get('user-id')]['name']
                );
                $save_entry_result = $this->saveHarvestTimeEntryToRedmine(
                    $redmine_entry_params,
                    $existing_redmine_time_entry
                );
            } catch (\Exception $e) {
                $this->io->error(
                    sprintf(
                        '<error>Failed to create time entry for redmine issue #%d, harvest id %d, exception %s</error>',
                        $redmine_issue['issue']['id'],
                        $harvest_entry->get('id'),
                        $e->getMessage()
                    )
                );
            } finally {
                $this->redmineClient->setImpersonateUser(null);
            }
        }
        // Log a success if there was one (or if dry run).
        if ($save_entry_result || $this->input->getOption('dry-run')) {
            $this->syncSuccesses[] = $this->formatSuccess(
                ($existing_redmine_time_entry) ? 'Updated' : 'Created',
                $redmine_issue['issue']['id'],
                $harvest_entry
            );
        }
        // If no save entry result, and not a dry run, log an error.
        if (!$save_entry_result && !$this->input->getOption('dry-run')) {
            $this->userTimeEntryErrors[$harvest_entry->get('user-id')]['unable-to-sync'][] = [
                'entry' => $harvest_entry,
            ];
            $this->syncErrors[$harvest_entry->get('id')] = $this->formatError('UNABLE_TO_SYNC', $harvest_entry);
        }

        return $save_entry_result;
    }

    /**
     * Get project data for Redmine projects with Harvest IDs.
     *
     * @return array
     */
    protected function getHarvestDataForProjects()
    {
        $this->io->section(sprintf('Getting time entries data for %d Redmine projects', count($this->projectMap)));
        $this->io->progressStart(count($this->projectMap));
        foreach ($this->projectMap as $harvest_id => $project) {
            $redmine_project_name = current(array_values($project));
            $project_data_result = $this->harvestClient->getProject($harvest_id);
            if ($project_data_result->get('code') !== 200) {
                $this->output->writeln(sprintf(
                    '- <error>Could not get project data for Harvest ID %d associated with Redmine project %s!',
                    $harvest_id,
                    $redmine_project_name
                ));
                continue;
            }
            $entries = $this->getHarvestTimeEntries($project_data_result);
            if (count($entries)) {
                foreach ($entries as $entry) {
                    $this->cachedHarvestEntries[$entry->get('id')] = $entry;
                }
            }

            $this->io->progressAdvance();
        }
        $this->io->progressFinish();

        array_filter($this->cachedHarvestEntries);
    }

    /**
     * Return a clickable URL of the time entry in question.
     *
     * @param \Harvest\Model\DayEntry $entry
     *
     * @return string
     */
    protected function getClickableTimeEntryUrl($entry)
    {
        return sprintf(
            'https://%s.harvestapp.com/time/day/%s/%d#timesheet_day_entry_%d',
            $this->config['auth']['harvest']['account'],
            str_replace('-', '/', $entry->get('spent-at')),
            $entry->get('user-id'),
            $entry->get('id')
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $this->io = $io;
        // Set input/output for use in other methods.
        $this->input = $input;
        $this->output = $output;
        // Set the Harvest Range.
        $this->setRange($input);
        $range = sprintf('%s to %s', $this->getRange()->from(), $this->getRange()->to());
        $io->title(sprintf('Sumac time sync from  %s', $range));
        // Load configuration.
        $this->setConfig();

        // Initialize the Harvest client.
        $this->setHarvestClient();

        // Initialize the Redmine client.
        $this->setRedmineClient();

        // Configure PSpell.
        $this->configurePSpell();

        // Map harvest projects to redmine projects.
        $this->populateProjectMap();

        // Cache redmine time entries.
        $this->cacheRedmineTimeEntries();

        // Get map of Redmine users to Harvest IDs.
        $this->populateUserMap();

        // Get Harvest time entries for those found in the project map.
        /* @var \Harvest\Model\Result $projects */
        $this->getHarvestDataForProjects();

        if (!count($this->cachedHarvestEntries)) {
            $this->io->comment('No entries found for logging!');

            return true;
        }

        // Sync entries.
        $this->io->section('Processing entries');
        $this->io->progressStart(count($this->cachedHarvestEntries));

        $spell_check_only = !empty($this->config['sync']['projects']['spell_check_only']) ? $this->config['sync']['projects']['spell_check_only'] : [];
        foreach ($this->cachedHarvestEntries as $harvest_entry) {
            $this->spellCheckEntry($harvest_entry);

            if (!in_array($harvest_entry->get('project-id'), $spell_check_only)) {
                $this->syncEntry($harvest_entry);
            }

            $this->io->progressAdvance();
        }
        $this->io->progressFinish();

        // If not a dry run, make another call to Redmine to ensure that the entry was really created. This is
        // necessary since Redmine API doesn't always return errors when making POST or PUT requests.
        $this->redmineTimeEntries = [];
        $this->cacheRedmineTimeEntries();
        foreach ($this->syncedHarvestRecords as $record) {
            if (!array_key_exists($record, $this->redmineTimeEntries)) {
                if (!array_key_exists($record, $this->syncErrors)) {
                    $this->userTimeEntryErrors[$record->get('user-id')]['harvest-id-not-synced'][] = [
                        'entry' => $record,
                    ];
                    $this->syncErrors[$record] = $this->formatError(
                        'HARVEST_ID_NOT_SYNCED',
                        $this->cachedHarvestEntries[$record]
                    );
                }
            }
        }

        $users = [];
        if (count($this->userTimeEntryErrors)) {
            foreach ($this->userTimeEntryErrors as $user => $errors) {
                if ($this->input->getOption('slack-notify')) {
                    $users[] = $this->slackUserMap[$user];
                    $this->logErrorsToSlack($user, $errors);
                }
            }
            $this->io->note(sprintf('Notified %s of time entry errors via Slack.', implode(', ', $users)));
        }

        if (count($this->syncSuccesses)) {
            $this->renderEntries('Successes', ['Message', 'Notes'], $this->syncSuccesses);
        }

        if (count($this->syncErrors)) {
            $this->renderEntries('Errors', ['Message', 'URL'], $this->syncErrors);
            $io->error(
                sprintf(
                    '%d errors and %d successes occurred during sync. See the logs.',
                    count($this->syncErrors),
                    count($this->syncSuccesses)
                )
            );
            // Return error code.
            return 1;
        }

        $this->io->success(sprintf('All done! Synced %d time entries.', count($this->syncSuccesses)));

        return 0;
    }

    /**
     * Format a table.
     */
    private function renderEntries($section_heading, $headers, $rows)
    {
        $this->io->section(($section_heading));
        $this->io->table($headers, $rows);
    }

    /**
     * Format an error for displaying in a table.
     */
    private function formatError($message, $entry, $notes = '')
    {
        return [
            'message' => !empty($notes) ? sprintf('%s - %s', $message, $notes) : $message,
            'harvest_url' => $this->getClickableTimeEntryUrl($entry),
        ];
    }

    /**
     * Format a success for displaying in a table.
     */
    private function formatSuccess($action, $redmine_id, $entry)
    {
        return [
            'message' => sprintf('%s %s hours on issue %d.', $action, $entry->get('hours'), $redmine_id),
            'notes' => $entry->get('notes'),
        ];
    }
}
