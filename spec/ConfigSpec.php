<?php

namespace spec\SavasLabs\Sumac;

use SavasLabs\Sumac\Config;
use PhpSpec\ObjectBehavior;

class ConfigSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->shouldHaveType(Config::class);
    }

    function it_fails_if_config_file_not_found()
    {
        $this->shouldThrow('\Exception')->duringLoadFile('file.yml');
    }

    function it_returns_an_array_if_file_is_loaded()
    {
        $this->loadConfig('config.example.yml')->shouldBeArray();
    }

    function it_loads_config_file_into_memory()
    {
        $this->loadFile('config.example.yml')->shouldBeString();
    }

   function it_fails_if_config_file_is_not_yaml()
    {
        $this->shouldThrow('\Exception')->during('parseYaml', ['"aws ... \"Branch\": $BITBUCKET_BRANCH, \"Date\": $(date +"%m-%d-%y"), \"Time\": $(date +"%T")}\"']);
    }
}
