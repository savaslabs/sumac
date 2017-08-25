<?php

namespace SavasLabs\Sumac;

use Symfony\Component\Yaml\Yaml;
use PhpSpec\Exception\Exception;

class Config
{
    public function loadConfig($config_file)
    {
        if (!file_exists($config_file)) {
            throw new Exception('Configuration file not found.');
        }

        return Yaml::parse(file_get_contents($config_file), true);
    }
}
