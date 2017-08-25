<?php

namespace SavasLabs\Sumac;

use Symfony\Component\Yaml\Yaml;
use PhpSpec\Exception\Exception;

class Config implements ConfigInterface
{
    public function loadConfig($config_file)
    {
        $contents = $this->loadFile($config_file);
        return $this->parseYaml($contents);
    }

    public function loadFile($file_name)
    {
        if (!file_exists($file_name)) {
            throw new Exception('Configuration file not found.');
        }
        return file_get_contents($file_name);
    }

    public function parseYaml($data)
    {
        return Yaml::parse($data, true);
    }
}
