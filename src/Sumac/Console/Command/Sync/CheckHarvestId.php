<?php

namespace Sumac\Console\Command\Sync;

use Harvest\Model\DayEntry;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckHarvestId extends BaseSyncCommand
{
    protected function configure()
    {
        $this->setName('sync:check-harvest-id')
            ->setDescription('Check if a Harvest ID exists.')
            ->setDefinition([
                new InputOption(
                    'config',
                    'c',
                    InputOption::VALUE_OPTIONAL,
                    'Path to configuration file. Leave empty if config.yml is in repository root.'
                ),
                new InputArgument(
                    'id',
                    null,
                    'The Harvest ID to check'
                ),
            ]);
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws \Harvest\Exception\HarvestException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $harvest_id = (int) $this->input->getArgument('id');
        $harvest_record = $this->findId($harvest_id);

        /** @var DayEntry $values */
        $values = $harvest_record->get('data');
        // Would be nice to print this in JSON_PRETTY_PRINT.
        $output->writeln($values->toXML());
    }

    /**
     * @param int $harvest_id
     * @return \Harvest\Model\Result
     * @throws \Harvest\Exception\HarvestException
     */
    public function findId(int $harvest_id)
    {
        $harvest_record = $this->harvestClient->getEntry($harvest_id);
        if ((int) $harvest_record->get('code') === 404) {
            $this->output->writeln(sprintf('Harvest ID %d not found!', $harvest_id));
            exit(1);
        }
        return $harvest_record;
    }
}
