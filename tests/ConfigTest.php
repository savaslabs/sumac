<?php

namespace Sumac\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ConfigTest extends TestCase
{

    public function testValidConfig()
    {
        $tmp_config = tempnam(sys_get_temp_dir(), 'sumac');
        $yaml_config = Yaml::dump(
            [
            'auth' => [
                'harvest' => [
                    'mail' => 'a@b.com',
                    'pass' => 'secret',
                ]
            ]
            ]
        );
        file_put_contents($tmp_config, $yaml_config);
        $config = new Config($tmp_config);
        $this->assertEquals($config->getHarvestPassword(), 'secret');
        $this->assertEquals($config->getHarvestMail(), 'a@b.com');
        unlink($tmp_config);
    }

    /**
     * @expectedException \Symfony\Component\Yaml\Exception\ParseException
     */
    public function testInvalidYaml()
    {
        $not_yaml = <<<'EOT'
 &  *  !  |  >  '  "  %  @  ` #, { asd a;sdasd }-@^qw3
EOT;
        $tmp_config = tempnam(sys_get_temp_dir(), 'sumac');
        file_put_contents($tmp_config, $not_yaml);
        $config = new Config($tmp_config);
    }
}
