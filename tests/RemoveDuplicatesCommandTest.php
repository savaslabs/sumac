<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Sumac\Console\Command\Sync\RemoveDuplicatesCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class RemoveDuplicatesCommandTest extends TestCase
{

    public function testRemoveDuplicatesCommandNonExistentConfig()
    {
        $application = new Application();
        $application->add(new RemoveDuplicatesCommand());
        $command = $application->find('sync:remove-duplicates');
        $command_tester = new CommandTester($command);
        try {
            $command_tester->execute(
                [
                    'command' => $command->getName(),
                    'IDs' => '[{"123":"123"}]',
                    '--config' => 'doesntexist.yml',
                ]
            );
        } catch (\Exception $exception) {
            $this->assertContains('Could not find the config.yml file at doesntexist.yml', $exception->getMessage());
        }
    }
}
