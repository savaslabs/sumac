<?php

namespace Sumac\Console\Command\Sync;

use Harvest\HarvestAPI;
use Sumac\Config\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Redmine;

abstract class BaseSyncCommand extends Command
{

    /**
     * @var Redmine\Client
     */
    protected $redmineClient;
    /**
     * @var SymfonyStyle
     */
    protected $io;
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var HarvestAPI
     */
    protected $harvestClient;

    const HARVEST_TIME_ENTRY_ID_FIELD = 20;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Exception
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->io = new SymfonyStyle($input, $output);
        try {
            $this->config = new Config($input->getOption('config'));
        } catch (\Exception $exception) {
            throw $exception;
        }

        $this->redmineClient = new Redmine\Client($this->config->getRedmineUrl(), $this->config->getRedmineApiKey());

        $this->harvestClient = new HarvestAPI();
        $this->harvestClient->setUser($this->config->getHarvestMail());
        $this->harvestClient->setPassword($this->config->getHarvestPassword());
        $this->harvestClient->setAccount($this->config->getHarvestAccount());
    }

    protected function getTimeEntries() :array
    {
        try {
            return $this->redmineClient->time_entry->all(
                [
                'limit' => 1000000,
                'offset' => 0,
                ]
            );
        } catch (\Exception $exception) {
            $this->io->error('Unable to connect to Redmine. Error: ' . $exception->getMessage());
            return [];
        }
    }
}
