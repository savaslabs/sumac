<?php

namespace Sumac\Console\Command\Sync;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FindOrphansCommand extends BaseSyncCommand
{
    private $short_form = false;

    protected function configure()
    {
        $this->setName('sync:find-orphans')
            ->setDescription('Find Redmine time entries whose Harvest IDs no longer exist.')
            ->setDefinition([
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
            ]);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Exception
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->short_form = $input->getOption('short');
    }

    /**
     * @param $short_form
     */
    public function setShortForm($short_form)
    {
        $this->short_form = $short_form;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Harvest\Exception\HarvestException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $time_entries = $this->getTimeEntries();
        $orphan_entries = [];
        foreach ($time_entries['time_entries'] as $entry) {
            $harvest_id = null;
            foreach ($entry['custom_fields'] as $field) {
                if (isset($field['id']) && $field['id'] == self::HARVEST_TIME_ENTRY_ID_FIELD) {
                    $harvest_id = $field['value'];
                    break;
                }
            }
            if (!$harvest_id) {
                continue;
            }
            $harvest_record = $this->harvestClient->getEntry($harvest_id);
            if ($harvest_record->get('code') == 404) {
                $orphan_entries[$harvest_id] = $this->short_form ? $entry['id'] : $entry;
            }
        }

        $this->io->write(json_encode($orphan_entries));
    }
}
