<?php

namespace Sumac\Console\Command\Sync;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FindDuplicatesCommand extends BaseSyncCommand
{

    private $short_form = false;

    protected function configure()
    {
        $this->setName('sync:find-duplicates')
            ->setDescription('Find duplicate time entries')
            ->setDefinition(
                [
                    new InputOption(
                        'config',
                        'c',
                        InputOption::VALUE_OPTIONAL,
                        'Path to configuration file. Leave empty if config.yml is in repository root.'
                    ),
                    new InputOption(
                        'short',
                        's',
                        null,
                        'Return only IDs rather than full time entries'
                    )
                ]
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->short_form = $input->getOption('short');
    }

    public function setShortForm($short_form)
    {
        $this->short_form = $short_form;
    }

    /**
     * Given an array of time entries retrieved from Redmine, get an indexed array of duplicates.
     *
     * @param  array $time_entries
     * @return array
     */
    public function getDuplicatesFromTimeEntries(array $time_entries) :array
    {
        $indexed = $this->indexEntriesByHarvestId($time_entries['time_entries']);
        return $this->getDuplicates($indexed);
    }

    public function getDuplicates(array $time_entries)
    {
        $duplicates = [];
        foreach ($time_entries as $harvest_id => $entry) {
            if (count($entry) > 1) {
                // If there's more than one entry, we have a duplicate.
                $sorted_entries = $entry;
                sort($sorted_entries);
                $duplicates[$harvest_id] = $sorted_entries;
            }
        }
        return $duplicates;
    }

    public function indexEntriesByHarvestId(array $time_entries)
    {
        $indexed = [];
        foreach ($time_entries as $entry) {
            $harvest_id = null;
            if (isset($entry['custom_fields'])) {
                foreach ($entry['custom_fields'] as $custom_field) {
                    if ($custom_field['id'] == self::HARVEST_TIME_ENTRY_ID_FIELD) {
                        $harvest_id = $custom_field['value'];
                        // Break out of the loop.
                        break;
                    }
                }
            }
            if (!$harvest_id) {
                // If there's no Harvest ID, skip indexing this entry.
                continue;
            }
            // Add the Redmine entry or just its ID to the index.
            $indexed[$harvest_id][] = $this->short_form ? $entry['id'] : $entry;
        }
        return $indexed;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return bool|int|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $time_entries = $this->getTimeEntries();
        if (!$time_entries) {
            return false;
        }

        $duplicates = $this->getDuplicatesFromTimeEntries($time_entries);

        $this->io->write(json_encode($duplicates));
    }
}
