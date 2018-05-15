<?php

namespace Sumac\Console\Command\Sync;

use Sumac\Config\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Redmine;

class RemoveDuplicatesCommand extends Command
{

    /**
     * @var SymfonyStyle
     */
    private $io;

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
        $this->setName('sync:remove-duplicates')
            ->setDescription('Purge duplicate time entries from Redmine.')
            ->setDefinition(
                [
                    new InputOption(
                        'config',
                        'c',
                        InputOption::VALUE_OPTIONAL,
                        'Path to configuration file. Leave empty if config.yml is in repository root.'
                    ),
                    new InputArgument(
                        'IDs',
                        1,
                        'A JSON encoded list of Harvest IDs as keys with the Redmine IDs as values.'
                    )
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Sort the array and show the user what they're about to do.
        $time_entries = json_decode($input->getArgument('IDs'), true);
        $time_entries = $this->sortTimeEntries($time_entries);
        $this->purgeTimeEntries($time_entries);
    }

    protected function purgeTimeEntries(array $time_entries)
    {
        $errors = [];
        $successes = [];
        $this->io->progressStart(count($time_entries));
        foreach ($time_entries as $harvest_id => $redmine_ids) {
            foreach ($redmine_ids as $redmine_id) {
                // TODO Handle errors here.
                $result = $this->redmineClient->time_entry->remove($redmine_id);
                if (!$result) {
                    $errors[] = $redmine_id;
                }
                if ($result) {
                    $successes[] = $redmine_id;
                }
            }
            $this->io->progressAdvance();
        }
        $this->io->progressFinish();
        // TODO: Log the errors and successes.
    }

    protected function sortTimeEntries($time_entries)
    {
        foreach ($time_entries as $harvest_id => &$redmine_ids) {
            sort($redmine_ids, SORT_NUMERIC);
            // If more than one duplicate, keep the most recent Redmine time entry and remove the oldest ones.
            if (count($redmine_ids) > 1) {
                array_pop($redmine_ids);
            }
        }
        return $time_entries;
    }
}
