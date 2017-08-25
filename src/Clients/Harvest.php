<?php

namespace SavasLabs\Sumac\Clients;

use Harvest\HarvestAPI;

class Harvest
{
    private $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    public function getClient()
    {
        $client = new HarvestAPI();
        $client->setUser($this->config['mail']);
        $client->setPassword($this->config['pass']);
        $client->setAccount($this->config['account']);
        return $client;
    }
}
