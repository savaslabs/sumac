<?php

namespace Sumac\Console\Command\Sync;

use Sumac\Config\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Redmine;

class FindDuplicatesCommand extends Command
{

    const HARVEST_TIME_ENTRY_ID_FIELD = 20;

    /** @var Config */
    private $config;
    /** @var Redmine\Client */
    private $redmineClient;
    /** @var SymfonyStyle */
    private $io;
    /** @var bool */
    private $short_form = FALSE;

    protected function configure() {
        $this->setName('sync:find-duplicates')
            ->setDescription('Find duplicate time entries')
            ->setDefinition([
                new InputOption(
                    'short',
                    's',
                    null,
                    'Return only IDs rather than full time entries'
                )
            ])
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output) {
        $this->io = new SymfonyStyle($input, $output);
        try {
            $this->config = new Config();
        }
        catch (\Exception $exception) {
            $this->io->error($exception->getMessage());
            return false;
        }

        $this->short_form = $input->getOption('short');

        $this->redmineClient = new Redmine\Client($this->config->getRedmineUrl(), $this->config->getRedmineApiKey());
    }

    protected function getTimeEntries() :array {
        try {
            return $this->redmineClient->time_entry->all([
                'limit' => 1000000,
                'offset' => 0,
            ]);
        }
        catch (\Exception $exception) {
            $this->io->error('Unable to connect to Redmine. Error: ' . $exception->getMessage());
            return [];
        }
    }

    protected function getDuplicatesFromTimeEntries(array $time_entries) :array {
        $indexed = $this->indexEntriesByHarvestId($time_entries['time_entries']);
        return $this->filterDuplicates($indexed);
        foreach ($time_entries['time_entries'] as $entry) {
            $harvest_id = NULL;
            foreach ($entry['custom_fields'] as $custom_field) {
                if ($custom_field['id'] == self::HARVEST_TIME_ENTRY_ID_FIELD) {
                    $harvest_id = $custom_field['value'];
                    // Break out of the loop.
                    break;
                }
            }
            if (!$harvest_id) {
                // If there's no Harvest ID, skip this entry.
                continue;
            }
            // The first time we've seen a Harvest ID, it won't be in the $indexed array. On the second time
            // we've seen it, it will be in $indexed, so then we can add it to the duplicates.
            if (isset($indexed[$harvest_id])) {
               $duplicates[$harvest_id][] = $this->short_form ? $entry['id'] : $entry;
            }
            // Add the item to the indexed array with the Harvest ID as the key, so that on the second pass
            // we can see if it's already been found.
            $indexed[$harvest_id] = $entry['id'];
        }

        return $duplicates;
    }

    public function filterDuplicates(array $time_entries) {
        $duplicates = [];
        foreach ($time_entries as $harvest_id => $entry) {
            if (count($entry) > 1) {
                // If there's more than one entry, we have a duplicate.
                $sorted_entries = $entry;
                sort($sorted_entries);
                // Remove the last item, so we keep the newest time entry.
                array_pop($sorted_entries);
                $duplicates[$harvest_id] = $sorted_entries;
            }
        }
        return $duplicates;
    }

    protected function indexEntriesByHarvestId(array $time_entries) {
        $indexed = [];
        foreach ($time_entries as $entry) {
            $harvest_id = NULL;
            foreach ($entry['custom_fields'] as $custom_field) {
                if ($custom_field['id'] == self::HARVEST_TIME_ENTRY_ID_FIELD) {
                    $harvest_id = $custom_field['value'];
                    // Break out of the loop.
                    break;
                }
            }
            if (!$harvest_id) {
                // If there's no Harvest ID, skip this entry.
                continue;
            }
            $indexed[$harvest_id][] = $entry['id'];
        }
        return $indexed;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {

        $time_entries = $this->getTimeEntries();
        if (!$time_entries) {
            return false;
        }

        $duplicates = $this->getDuplicatesFromTimeEntries($time_entries);

        return $this->io->write(json_encode($duplicates));
    }

}