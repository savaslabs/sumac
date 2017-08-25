<?php

namespace spec\SavasLabs\Sumac\Clients;

use SavasLabs\Sumac\Clients\Harvest;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class HarvestSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $config = ['mail' => 'a@b.com', 'pass' => 'secret', 'account' => 'foo'];
        $this->beConstructedWith($config);
        $this->shouldHaveType(Harvest::class);
    }

    function it_should_return_a_client()
    {
        $config = ['mail' => 'a@b.com', 'pass' => 'secret', 'account' => 'foo'];
        $this->beConstructedWith($config);
        $this->getClient($config)->shouldReturnAnInstanceOf('Harvest\HarvestAPI');
    }
}
