<?php

namespace Sumac\Console\Command\Notify;

use Sumac\Config\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Redmine;
use Carbon\Carbon;

// This is the group ID in Redmine we use to determine whose an internal team member
define('SAVAS_GROUP_ID', 95);

class FindUnattendedTasks extends Command
{

    /**
     * @var SymfonyStyle
     */
    private $io;

    /**
     * @var Date range array
     */
    private $range;

    /**
     * @var Redmine\Client
     */
    private $redmineClient;

    /**
     * @var Config
     */
    private $config;

    protected function configure()
    {
        $this->setName('notify:find-unattended-tasks')
            ->setDescription('Show Redmine tasks that Savas has not responded to.')
            ->setDefinition(
                [
                    new InputArgument(
                        'date',
                        InputArgument::OPTIONAL,
                        'Date to sync data for. Defaults to current day.',
                        Carbon::create()->format('Ymd')
                    ),
                    new InputOption(
                        'config',
                        'c',
                        InputOption::VALUE_OPTIONAL,
                        'Path to configuration file. Leave empty if config.yml is in repository root.'
                    ),
                    new InputOption(
                        'slack-notify',
                        's',
                        null,
                        'If set, will attempt to send Slack notifications to users about errors in their time entries.'
                    ),
                ]
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        try {
            $this->config = new Config($input->getOption('config'));
        } catch (\Exception $exception) {
            throw $exception;
        }
        $this->redmineClient = new Redmine\Client($this->config->getRedmineUrl(), $this->config->getRedmineApiKey());
    }

    /**
     * Set the date range based on the 'date' argument.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     */
    private function setDateRange(InputInterface $input)
    {
        $range = $input->getArgument('date');
        if (strpos($range, ':') !== false) {
            list($from, $to) = explode(':', $range);
        } else {
            $to = $from = $range;
        }

        $this->range = [
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @return array
     *
     * Returns Redmine issue array for a given date range
     */
    protected function getIssuesForDateRange()
    {

        $created_string = '><' . $this->range['from'] . '|' .$this->range['to'];
        return $this->redmineClient->issue->all([
            'created_on' => $created_string,
            'limit' => 1000
        ]);
    }

    /**
     * @return array
     *
     * Returns IDs of anyone tagged in Redmine in the group "Savas Labs All"
     */
    protected function getAllSavasUserIDs()
    {

        $internal_users = $this->redmineClient->user->all([
            'group_id' => SAVAS_GROUP_ID,
        ]);

        foreach ($internal_users['users'] as $user) {
            $ids[] = $user['id'];
        }

        // The bot is user 1 but is a locked account and we cannot include with others in one API request
        $ids[] = 1;

        return $ids;
    }

    /**
     * @param $issues
     * @param $savas_user_ids
     * @return array
     *
     * Removes issues created by our team. We are only focusing on things we may
     * not be aware of due to others creating them but us not getting notified
     */
    protected function removeInternallyCreatedIssues($issues, $savas_user_ids)
    {

        $return = [];
        foreach ($issues as $issue) {
            // Don't add if they're in the author group
            if (in_array($issue['author']['id'], $savas_user_ids)) {
                // Do nothing
            } else {
                // Assume we haven't responded until we see a comment from the internal team
                $return[] = $issue;
                $details = $this->redmineClient->issue->show($issue['id'], [
                  'include' => [
                    'journals'
                  ]
                ]);

                foreach ($details['issue']['journals'] as $journal) {
                    // If there are no "notes" then no one has "commented" so we'll add it to the list
                    if (!empty($journal['notes']) && in_array($journal['user']['id'], $savas_user_ids)) {
                        array_pop($return);
                        break;
                    }
                }
            }
        }

        return $return;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $this->setDateRange($input);
        $savas_users = $this->getAllSavasUserIDs();
        $prospective_issues = $this->getIssuesForDateRange();
        $resulting_issues = $this->removeInternallyCreatedIssues($prospective_issues['issues'], $savas_users);

        $slack_payload = [];
        $slack_payload['text'] = sprintf(
            "The following tasks have been created by a *non-Savasian* between the date range of %s and %s",
            $this->range['from'],
            $this->range['to']
        );
        $slack_payload['text'] .= " and we have not responded to them yet. They may require our attention:\n\n";

        $redmine_url = $this->config->getRedmineUrl();
        foreach ($resulting_issues as $issue) {
            $slack_payload['text'] .= sprintf(
                "Project: *%s* Author: *%s* Link: *<%s>*\n",
                $issue['project']['name'],
                $issue['author']['name'],
                $redmine_url . '/issues/' . $issue['id'] . '|' . $issue['subject'] . ' (' . $issue['id'] . ")"
            );
        }

        $slack_message = json_encode($slack_payload);

        $slack_url = $this->config->getSlackWebhookNotifyUrl();

        $http_client = new \GuzzleHttp\Client(['base_uri' => 'https://hooks.slack.com']);
        $r = $http_client->request(
            'POST',
            $slack_url,
            [
            'body' => $slack_message,
            ]
        );

        if ($r->getStatusCode() == '200') {
            return 0;
        } else {
            return 1;
        }
    }
}
