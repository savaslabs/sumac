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
    /** @var bool */
    private $errors;
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
    /** @var array */
    private $syncErrors;
    /** @var array */
    private $syncSuccesses;
    /** @var array */
    private $skipProjects;
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
     * Cache all Redmine time entries in a local array.
     */
    private function cacheRedmineTimeEntries()
    {
        $all_time_entries = $this->redmineClient->time_entry->all(array('limit' => 1000000, 'offset' => 0));
        if (!isset($all_time_entries['time_entries'])) {
            $this->output->writeln(
                '<error>Invalid time entry list returned from API.'
                .' Possible that API token is not set correctly.</error>'
            );
            $this->errors = true;

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
                            $this->errors = true;

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
            'no-issue-number' => 'Time entries with no issue number',
            'missing-issue' => 'Time entries where no matching redmine issue was found',
            'issue-not-in-project' => 'Redmine issue\'s project doesn\'t match up with the Harvest project.',
            'spelling' => 'Possible spelling errors',
            'rounding' => 'Possible rounding errors',
        ];

        $error_message_formatters = [
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
                    $error['entry']->get('notes'),
                    $this->getClickableTimeEntryUrl($error['entry'])
                );
            },
            'spelling' => function ($error) {
                return sprintf(
                    "%s -- %s\n_Potential misspellings: %s_\n%s",
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['entry']->get('notes'),
                    implode(', ', $error['spelling-errors']),
                    $this->getClickableTimeEntryUrl($error['entry'])
                );
            },
            'rounding' => function ($error) {
                return sprintf(
                    "%s -- %s\nHours were: %.2f, should be %.2f\n%s",
                    substr($error['entry']->get('spent-at'), 0, 10),
                    $error['entry']->get('notes'),
                    $error['entry']->get('hours'),
                    $error['rounded-hours'],
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
        $active_users = $this->redmineClient->user->all(['limit' => 1000]);
        $locked_users = $this->redmineClient->user->all(['limit' => 1000, 'status' => 3]);
        $users = array_merge($active_users['users'], $locked_users['users']);
        foreach ($users as $user) {
            if (isset($user['custom_fields'])) {
                foreach ($user['custom_fields'] as $custom_field) {
                    if ($custom_field['name'] == 'Harvest ID' && !empty($custom_field['value'])) {
                        $this->userMap[trim($custom_field['value'])] = $user['login'];
                    }
                    if ($custom_field['name'] == 'Slack ID' && !empty($custom_field['value'])) {
                        $redmine_slack_user_map[$user['login']] = trim($custom_field['value']);
                    }
                }
            }
        }

        $redmine_harvest_map = array_flip($this->userMap);
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
            // TODO: Better error message.j
            throw new Exception('Unable to load project data');
        }
        if ((isset($this->config['sync']['projects']['exclude'])) && (in_array(
            $project_data->get('id'),
            $this->config['sync']['projects']['exclude']
        ))) {
            $this->skipProjects[] = $project_data->get('name');

            return;
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
            $this->syncErrors[] = $this->formatError(
                'NO_ISSUE_NUMBER',
                $entry
            );

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

            $this->errors = true;
            $this->syncErrors[] = $this->formatError(
                'ISSUE_NOT_FOUND',
                $entry
            );

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
                $this->syncErrors[] = $this->formatError(
                    'ISSUE_PROJECT_MISMATCH',
                    $entry
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
     * @param \Harvest\Model\DayEntry $harvest_entry
     *
     * @return array
     */
    protected function getExistingRedmineIssueTimeEntries(DayEntry $harvest_entry) {
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
            $time_entry_api->create($redmine_time_entry_params);
        } else {
            // Update existing entry.
            $time_entry_api->update(
                $existing_redmine_time_entry['id'],
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

        $existing_redmine_time_entry = $this->getExistingRedmineIssueTimeEntries(
            $harvest_entry
        );

        // If there are existing Redmine time entries matching this harvest entry and we are not updating, skip.
        if ($existing_redmine_time_entry !== false > 0 && !$this->input->getOption('update')) {
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
        if ($save_entry_result || $this->input->getOption('dry-run')) {
            $this->syncSuccesses[] = $this->formatSuccess(
                ($existing_redmine_time_entry === false) > 0 ? 'Updated' : 'Created',
                $redmine_issue['issue']['id'],
                $harvest_entry
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
        $this->io->section(sprintf('Getting time entries data for %d Redmine projects', count($this->projectMap)));
        $this->io->progressStart(count($this->projectMap));
        foreach ($this->projectMap as $harvest_id => $project) {
            $project_names = [];
            foreach ($project as $key => $projects) {
                $project_names[] = current($projects);
            }
            $project_data_result = $this->harvestClient->getProject($harvest_id);
            if ($project_data_result->get('code') !== 200) {
                $this->output->writeln(sprintf(
                    '- <error>Could not get project data for Harvest ID %d associated with Redmine project %s!',
                    $harvest_id,
                    implode(' - ', $project_names)
                ));
                continue;
            }
            $entries = $this->getHarvestTimeEntries($project_data_result);
            if (count($entries)) {
                foreach ($entries as $entry) {
                    $project_data[] = $entry;
                }
            }

            $this->io->progressAdvance();
        }
        $this->io->progressFinish();

        return array_filter($project_data);
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
        $this->errors = false;
        // Set the Harvest Range.
        $this->setRange($input);
        $range = sprintf('%s to %s', $this->getRange()->from(), $this->getRange()->to());
        $io->title(sprintf('Sumac time sync from  %s', $range));
        // Load configuration.
        $this->setConfig();

        // Configure PSpell.
        $this->configurePSpell();

        // Initialize the Harvest client.
        $this->setHarvestClient();

        // Initialize the Redmine client.
        $this->setRedmineClient();

        // Cache redmine time entries.
        $this->cacheRedmineTimeEntries();

        // Map harvest projects to redmine projects.
        $this->populateProjectMap();

        // Get map of Redmine users to Harvest IDs.
        $this->populateUserMap();

        // Get Harvest time entries for those found in the project map.
        /** @var \Harvest\Model\Result $projects */
        $entries = $this->getHarvestDataForProjects();
        if (count($this->skipProjects)) {
            $this->io->warning(
                sprintf(
                    'Skipped projects %s, in config.yml excludes list',
                    implode(', ', $this->skipProjects)
                )
            );
        }

        $entries_to_log = array_filter($entries, function ($entry) {
            return strpos($entry->get('notes'), '#') !== false;
        });

        $this->io->note(
            sprintf(
                'Found %d entries with possible Redmine IDs out of %d total',
                count($entries_to_log),
                count($entries)
            )
        );

        // Sync entries.
        $this->io->section('Processing entries');
        $this->io->progressStart(count($entries_to_log));
        foreach ($entries_to_log as $harvest_entry) {
            $this->syncEntry($harvest_entry);
            $this->io->progressAdvance();
        }
        $this->io->progressFinish();

        $users = [];
        foreach ($this->userTimeEntryErrors as $user => $errors) {
            if ($this->input->getOption('slack-notify')) {
                $users[] = $this->slackUserMap[$user];
                $this->logErrorsToSlack($user, $errors);
            }
        }
        $this->io->note(sprintf('Notified %s of time entry errors via Slack.', implode(', ', $users)));

        if ($this->errors) {
            $this->renderEntries('Errors', ['Message', 'URL'], $this->syncErrors);
            $this->renderEntries('Successes', ['Message', 'Notes'], $this->syncSuccesses);
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

        $io->table(
            [
                'Message',
                'Notes',
            ],
            $this->syncSuccesses
        );
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
    private function formatError($message, $entry)
    {
        return [
            'message' => $message,
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
