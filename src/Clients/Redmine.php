<?php

namespace SavasLabs\Sumac\Clients;

use Redmine\Client;

class Redmine
{
    private $url;
    private $apikey;

    public function __construct($url, $apikey)
    {
        $this->url = $url;
        $this->apikey = $apikey;
    }

    public function getClient()
    {
        return new Client($this->url, $this->apikey);
    }

}
