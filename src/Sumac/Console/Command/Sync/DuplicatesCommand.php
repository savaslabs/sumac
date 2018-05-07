<?php

namespace Sumac\Console\Command\Sync;

use Sumac\Config\Config;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Input\Input;
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

class DuplicatesCommand extends Command
{

    const HARVEST_TIME_ENTRY_ID_FIELD = 20;
    private $config;
    private $redmineClient;
    private $io;

    protected function configure() {
        $this->setName('sync:find-duplicates')
            ->setDescription('Find duplicate time entries');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->comment('Searching for duplicates');
        try {
            $this->config = new Config();
        }
        catch (\Exception $exception) {
            $this->io->error($exception->getMessage());
            return;
        }

        $this->redmineClient = new Redmine\Client($this->config->getRedmineUrl(), $this->config->getRedmineApiKey());
        $time_entries = $this->redmineClient->time_entry->all(array(
              'limit' => 1000000,
              'offset' => 0,
        ));
        $indexed = [];
        $duplicates = [];
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
            if (isset($indexed[$harvest_id])) {
               $duplicates[$harvest_id][] = $entry;
            }
            $indexed[$harvest_id] = $entry;
        }

        $io->write(json_encode($duplicates, JSON_PRETTY_PRINT));
    }
}