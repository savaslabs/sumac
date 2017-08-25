<?php

namespace spec\SavasLabs\Sumac\Clients;

use SavasLabs\Sumac\Clients\Redmine;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class RedmineSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->beConstructedWith('https://localhost', '123');
        $this->shouldHaveType(Redmine::class);
    }

    function it_should_return_a_client()
    {
        $this->beConstructedWith('http://localhost', '123');
        $this->getClient()->shouldReturnAnInstanceOf('Redmine\Client');
    }
}
