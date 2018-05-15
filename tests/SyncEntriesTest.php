<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
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
        $sync_command->populateProjectMap();
    }
}
