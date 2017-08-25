<?php

namespace spec\SavasLabs\Sumac;

use SavasLabs\Sumac\Config;
use PhpSpec\ObjectBehavior;

class ConfigSpec extends ObjectBehavior
{
    public function it_is_initializable()
    {
        $this->shouldHaveType(Config::class);
    }

    public function it_fails_if_config_file_not_found()
    {
        $this->shouldThrow('\Exception')->duringLoadConfig('file.yml');
    }

    public function it_returns_an_array_if_file_is_loaded()
    {
        $this->loadConfig('config.example.yml')->shouldBeArray();
    }
}
