<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Redmine\Api\Project;
use Redmine\Client;
use Sumac\Config\Config;
use Sumac\Console\Command\Sync\SyncCommand;

class SyncEntriesTest extends TestCase
{

    /**
     * @expectedException \Exception
     */
    public function testProjectMapSet()
    {
        $sync_command = new SyncCommand();
        $tmp_config = tempnam(sys_get_temp_dir(), 'sumac');
        $config_yaml = <<<'EOT'
auth:
  redmine:
    apikey: 'fake'
    url: 'localhost'
EOT;
        file_put_contents($tmp_config, $config_yaml);
        $config = new Config($tmp_config);
        $sync_command->setConfig($config);
        $sync_command->setRedmineClient();
        $sync_command->populateProjectMap();
    }

    public function testDebugProjectMap()
    {
        $sync_command = new SyncCommand();
        $tmp_config = tempnam(sys_get_temp_dir(), 'sumac');
        $config_yaml = <<<'EOT'
auth:
  redmine:
    apikey: 'fake'
    url: 'localhost'
sync:
  projects:
    debug_projects:
      - 11042639
EOT;
        file_put_contents($tmp_config, $config_yaml);
        $config = new Config($tmp_config);
        $sync_command->setConfig($config);
        $projects = [
            'projects' => [
                [
                    'id' => 123,
                    'name' => 'Some project',
                    'custom_fields' => [
                        0 => [
                            'name' => ''
                        ],
                        1 => [
                            'name' => '',
                        ],
                        2 => [
                            'id' => 17,
                            'name' => 'Harvest Project ID(s)',
                            'value' => '11042639'
                        ]
                    ],
                ],
            ],
        ];

        $project_mock = $this->createMock(Project::class);
        $project_mock
            ->expects($this->once())
            ->method('all')
            ->willReturn($projects);
        $api_mock = $this->createMock(Client::class);
        $api_mock->expects($this->once())
            ->method('api')
            ->willReturn($project_mock);
        $sync_command->setRedmineClient($api_mock);
        $sync_command->populateProjectMap();
        $this->assertEquals([
            11042639 => [
                123 => 'Some project',
            ],
        ], $sync_command->getProjectMap());
    }
}
