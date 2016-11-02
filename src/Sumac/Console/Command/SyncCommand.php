<?php

namespace Sumac\Console\Command;

use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Input\InputInterface;
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
    /** @var array */
    private $config;
    /** @var bool */
    private $errors;
    /** @var \Redmine\Client */
    private $redmineClient;
    /** @var \Harvest\HarvestAPI */
    private $harvestClient;

    /** @var array
     * Maps harvest IDs to Redmine IDs
     */
    protected $projectMap;

    /** @var array
     * Maps Redmine users to Harvest IDs
     */
    protected $userMap;

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
                        'Do a simulation of what would happen'
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
        $this->pspellLink = pspell_new('en');
        foreach ($this->config['spellcheck']['custom_words'] as $word) {
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
        $env_vars = false;
        // If environment variables are set, use them.
        if (getenv('SUMAC_HARVEST_MAIL')) {
            $this->config['auth']['harvest']['mail'] = getenv('SUMAC_HARVEST_MAIL');
            $env_vars = true;
            $this->config['auth']['harvest']['pass'] = getenv('SUMAC_HARVEST_PASS');
            $this->config['auth']['harvest']['account'] = getenv('SUMAC_HARVEST_ACCOUNT');
            $this->config['auth']['redmine']['apikey'] = getenv('SUMAC_REDMINE_APIKEY');
            $this->config['auth']['redmine']['url'] = getenv('SUMAC_REDMINE_URL');
        }
        if (getenv('SUMAC_SYNC_PROJECTS_EXCLUDE')) {
            $this->config['sync']['projects']['exclude'] = explode(',', getenv('SUMAC_SYNC_PROJECTS_EXCLUDE'));
        }
        if ($env_vars) {
            return;
        }

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
     * Logs errors to slack for a given harvest user.
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
                    '<warning>Slack user not defined for harvest user %s.</warning>',
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
            'no-issue-number' => 'Time entries with no issue number',
            'missing-issue' => 'Time entries where no matching redmine issue was found',
            'issue-not-in-project' => 'Matching redmine issue found, but it was in a different project',
            'spelling' => 'Possible spelling errors',
            'rounding' => 'Possible rounding errors',
        ];

        $error_message_formatters = [
            'no-issue-number' => function ($error) {
                return sprintf(
                    '%s (%s) -- %s',
                    $error['entry']->get('project-id'),
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['entry']->get('notes')
                );
            },
            'missing-issue' => function ($error) {
                return sprintf(
                    '%s -- %s',
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['entry']->get('notes')
                );
            },
            'issue-not-in-project' => function ($error) {
                return sprintf(
                    '%s -- %s',
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['entry']->get('notes')
                );
            },
            'spelling' => function ($error) {
                return sprintf(
                    "%s -- %s\n_Potential misspellings: %s_\n",
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['entry']->get('notes'),
                    implode(', ', $error['spelling-errors'])
                );
            },
            'rounding' => function ($error) {
                return sprintf(
                    "%s -- %s\nHours were: %.2f, should be %.2f",
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['entry']->get('notes'),
                    $error['entry']->get('hours'),
                    $error['rounded-hours']
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
                    } else {
                        return 0;
                    }
                }
            );

            $fields[] = [
                'title' => $error_category_titles[$category],
                'value' => implode("\n", array_map($error_message_formatters[$category], $errors_array)),
            ];
        }

        // Set time of day so slackbot can be a bit more conversational.
        $hour = date('H', time());
        if ($hour > 6 && $hour <= 11) {
            $greeting = 'Good morning';
        } elseif ($hour > 11 && $hour <= 16) {
            $greeting = 'Good afternoon';
        } elseif ($hour > 16 && $hour <= 23) {
            $greeting = 'Good evening';
        } else {
            $greeting = 'Sumac never sleeps';
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
                        foreach ($project_ids as $project_id) {
                            $this->projectMap[trim($project_id)][] = [$project['id'] => $project['name']];
                        }
                    }
                }
            }
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
        $this->setRedmineClient();
        $users = $this->redmineClient->user->all(['limit' => 1000]);
        foreach ($users['users'] as $user) {
            foreach ($user['custom_fields'] as $custom_field) {
                if ($custom_field['name'] == 'Harvest ID' && !empty($custom_field['value'])) {
                    $this->userMap[trim($custom_field['value'])] = $user['login'];
                }
                if ($custom_field['name'] == 'Slack ID' && !empty($custom_field['value'])) {
                    $redmine_slack_user_map[$user['login']] = trim($custom_field['value']);
                }
            }
        }

        $redmine_harvest_map = array_flip($this->userMap);
        foreach ($redmine_slack_user_map as $redmine_user => $slack_user) {
            $this->slackUserMap[$redmine_harvest_map[$redmine_user]] = $slack_user;
        }
        if (!count($this->userMap)) {
            throw new Exception('Unable to populate usermap!');
        }
    }

    /**
     * Get all redmine time entries which might need to be synced.
     *
     * @param \Harvest\Model\Result $projects_array
     *                                              Array of harvest projects
     *
     * @return array
     */
    protected function getHarvestTimeEntries($projects_array)
    {
        $entries = [];

        // Get entries.
        foreach ($projects_array as $projects) {
            /** @var $projects \Harvest\Model\Project */
            $project = $projects->get('data');
            if (!is_object($project)) {
                $this->output->writeln('- <error>Could not get project data!');
                continue;
            }
            if ((isset($this->config['sync']['projects']['exclude'])) && (in_array(
                $project->get('id'),
                $this->config['sync']['projects']['exclude']
            ))) {
                $this->output->writeln(
                    sprintf(
                        '<comment>- Skipping project %s, in exclude list</comment>',
                        $project->get('name')
                    )
                );
                continue;
            }

            $this->output->writeln('<comment>- Retrieving time entry data for '
                .$project->get('name')
                .' ('.$project->get('id')
                .')</comment>');
            $project_entries = $this->harvestClient->getProjectEntries(
                $project->get('id'),
                $this->getRange()
            );
            foreach ($project_entries->get('data') as $harvest_entry) {
                $entries[] = $harvest_entry;
            }
        }

        return $entries;
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
            // The resulting value is not a number.
            $this->userTimeEntryErrors[$entry->get('user-id')]['no-number'][] = [
                'entry' => $entry,
            ];
            $this->output->writeln('<comment>Skipping entry, it does not look like there is an issue number here.');

            return false;
        }

        $this->setRedmineClient();
        $issue_api = new Redmine\Api\Issue($this->redmineClient);
        $redmine_issue = $issue_api->show($redmine_issue_number);

        if (!$redmine_issue || !isset($redmine_issue['issue']['project']['id'])) {
            // Issue doesn't exist in Redmine; this is probably a GitHub issue reference.
            $this->userTimeEntryErrors[$entry->get('user-id')]['missing-issue'][] = [
                'entry' => $entry,
            ];
            $this->output->writeln(
                sprintf(
                    '<error>- Could not find Redmine issue %d!</error>',
                    $redmine_issue_number
                )
            );
            $this->errors = true;

            return false;
        }

        // Validate that issue ID exists in project.
        if (isset($this->projectMap[$entry->get('project-id')])) {
            $project_names = [];
            $found = false;
            foreach ($this->projectMap[$entry->get('project-id')] as $project) {
                $project_names[] = current($project);
                if (isset($project[$redmine_issue['issue']['project']['id']])) {
                    $found = true;
                }
            }
            if (!$found) {
                // The issue number doesn't belong to the Harvest project we are looking at
                // time entries for, so continue. It's probably a GitHub issue ref.
                $this->userTimeEntryErrors[$entry->get('user-id')]['issue-not-in-project'][] = [
                    'entry' => $entry,
                ];
                $this->output->writeln(
                    sprintf(
                        '<error>- Issue %d does not exist in the Redmine project(s) %s. Time entry: \'%s\'</error>',
                        $redmine_issue_number,
                        implode(',', $project_names),
                        $entry->toXML()
                    )
                );
                $this->errors = true;

                return false;
            }
        }

        return $redmine_issue;
    }

    /**
     * Get Redmine time entries matching a harvest entry.
     *
     * @param array                   $redmine_issue
     * @param \Harvest\Model\DayEntry $harvest_entry
     *
     * @return array
     */
    protected function getExistingRedmineIssueTimeEntries(
        array $redmine_issue,
        DayEntry $harvest_entry
    ) {
        $redmine_search_params = [
            'issue_id' => $redmine_issue['issue']['id'],
            'limit' => 10000,
        ];

        $this->setRedmineClient();
        $time_entry_api = new Redmine\Api\TimeEntry($this->redmineClient);
        $redmine_time_entries = $time_entry_api->all($redmine_search_params);
        $matching_entries = [];

        if (isset($redmine_time_entries['total_count']) && $redmine_time_entries['total_count'] > 0) {
            // There might be a match.
            foreach ($redmine_time_entries['time_entries'] as $redmine_time_entry) {
                // TODO: There should always be a comment in the time entry,
                // but this is not the case for time entry ID 3494 and others.
                if (isset($redmine_time_entry['comments']) && strpos(
                    $redmine_time_entry['comments'],
                    $harvest_entry->get('id')
                ) !== false) {
                    $matching_entries[] = $redmine_time_entry;
                }
            }
        }

        return $matching_entries;
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
        ];
    }

    /**
     * Saves a harvest entry to Redmine, or updates an existing one if it exists.
     *
     * @param array $redmine_time_entry_params
     * @param array $existing_redmine_time_entries
     *
     * @return bool
     */
    protected function saveHarvestTimeEntryToRedmine(
        array $redmine_time_entry_params,
        array $existing_redmine_time_entries
    ) {
        $time_entry_api = new Redmine\Api\TimeEntry($this->redmineClient);
        if (count($existing_redmine_time_entries) === 0) {
            $time_entry_api->create($redmine_time_entry_params);
        } else {
            // Update existing entry.
            $time_entry_api->update(
                $existing_redmine_time_entries[0]['id'],
                $redmine_time_entry_params
            );
        }

        return true;
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

        $redmine_issue = $this->getRedmineIssue($harvest_entry);
        if (!$redmine_issue) {
            return false;
        }

        $existing_redmine_time_entries = $this->getExistingRedmineIssueTimeEntries(
            $redmine_issue,
            $harvest_entry
        );

        // If there are existing Redmine time entries matching this harvest entry and we are not updating, skip.
        if (count($existing_redmine_time_entries) > 0 && !$this->input->getOption('update')) {
            return false;
        }

        // Or if there is more than one matching redmine time entry, throw an error and continue.
        if (count($existing_redmine_time_entries) > 1) {
            $this->output->writeln(sprintf(
                '<error>Multiple Redmine time entries matching harvest time entry %d. See entries %s</error>',
                $harvest_entry->get('id'),
                json_encode($existing_redmine_time_entries)
            ));
            $this->errors = true;

            return false;
        }

        // If Harvest user is not mapped to a redmine user, throw an error and continue.
        if (!isset($this->userMap[$harvest_entry->get('user-id')])) {
            $this->output->writeln(
                sprintf(
                    '<error>No mapping is defined for user %d</error>',
                    $harvest_entry->get('user-id')
                )
            );
            $this->errors = true;

            return false;
        }

        // Log the entry.
        $redmine_entry_params = $this->populateRedmineTimeEntry(
            $redmine_issue,
            $harvest_entry
        );

        // Check rounding.
        if ($redmine_entry_params['hours'] != $harvest_entry->get('hours')) {
            $this->userTimeEntryErrors[$harvest_entry->get('user-id')]['rounding'][] = [
                'entry' => $harvest_entry,
                'rounded-hours' => $redmine_entry_params['hours'],
            ];
        }

        $save_entry_result = false;
        $this->setRedmineClient();
        if (!$this->input->getOption('dry-run')) {
            try {
                $this->redmineClient->setImpersonateUser(
                    $this->userMap[$harvest_entry->get('user-id')]
                );
                $save_entry_result = $this->saveHarvestTimeEntryToRedmine(
                    $redmine_entry_params,
                    $existing_redmine_time_entries
                );
            } catch (\Exception $e) {
                $this->output->writeln(
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
        if ($save_entry_result || $this->input->getOption('dry-run')) {
            $this->output->writeln(
                sprintf(
                    '<comment>%s time entry for issue #%d with %s hours (Harvest hours: %s)</comment>',
                    count($existing_redmine_time_entries) > 0 ? 'Updated' : 'Created',
                    $redmine_issue['issue']['id'],
                    $redmine_entry_params['hours'],
                    $harvest_entry->get('hours')
                )
            );
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
        $project_data = [];
        foreach ($this->projectMap as $harvest_id => $project) {
            $project_names = [];
            foreach ($project as $key => $projects) {
                $project_names[] = current($projects);
            }
            $this->output->writeln(sprintf(
                '<info>Getting data for Harvest project %d from project %s</info>',
                $harvest_id,
                implode(' - ', $project_names)
            ));
            $project_data_result = $this->harvestClient->getProject($harvest_id);
            if ($project_data_result->get('code') !== 200) {
                $this->output->writeln(sprintf(
                    '- <error>Could not get project data for Harvest ID %d associated with Redmine project %s!',
                    $harvest_id,
                    implode(' - ', $project_names)
                ));
                continue;
            }
            $project_data[] = $project_data_result;
        }

        return $project_data;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Set input/output for use in other methods.
        $this->input = $input;
        $this->output = $output;
        $this->errors = false;

        // Set the Harvest Range.
        $this->setRange($input);
        $output->writeln('<info>Syncing data for time period between '.
            $this->getRange()->from().
            ' and '.
            $this->getRange()->to().'</info>');

        // Load configuration.
        $this->setConfig();

        // Configure PSpell.
        $this->configurePSpell();

        // Initialize the Harvest client.
        $this->setHarvestClient();

        // Initialize the Redmine client.
        $this->setRedmineClient();

        // Map harvest projects to redmine projects.
        $this->populateProjectMap();

        // Get map of Redmine users to Harvest IDs.
        $this->populateUserMap();

        // Get Harvest project entries for those found in the project map.
        /** @var \Harvest\Model\Result $projects */
        $projects = $this->getHarvestDataForProjects();

        $output->writeln(sprintf(
            '<info>Getting data for %d projects</info>',
            count($projects)
        ));
        $entries = $this->getHarvestTimeEntries($projects);

        $entries_to_log = array_filter($entries, function ($entry) {
            return strpos($entry->get('notes'), '#') !== false;
        });

        $output->writeln(
            sprintf(
                '<info>Found %d entries with possible Redmine IDs out of %d total</info>',
                count($entries_to_log),
                count($entries)
            )
        );

        // Sync entries.
        foreach ($entries_to_log as $harvest_entry) {
            $redmine_project_names = [];
            foreach ($this->projectMap[$harvest_entry->get('project-id')] as $value) {
                $redmine_project_names[] = current($value);
            }

            $this->output->writeln(
                sprintf(
                    '<info>Processing entry: "%s" (%d) in Redmine project(s) "%s"</info>',
                    $harvest_entry->get('notes'),
                    $harvest_entry->get('id'),
                    implode(',', $redmine_project_names)
                )
            );
            $this->syncEntry($harvest_entry);
        }

        foreach ($this->userTimeEntryErrors as $user => $errors) {
            if ($this->getOption('slack-notify')) {
                $output->writeln(
                    sprintf(
                        '<info>Notifying %s of time entry errors over slack</info>',
                        $user
                    )
                );
                $this->logErrorsToSlack($user, $errors);
            }
        }

        if ($this->errors) {
            $output->writeln('<error>Errors occurred during sync. See the logs.</error>');
        }
        $output->writeln('<question>All done!</question>');
    }
}
