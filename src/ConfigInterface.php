<?php
/**
 * Created by PhpStorm.
 * User: kostajh
 * Date: 8/25/17
 * Time: 15:21
 */

namespace SavasLabs\Sumac;

interface ConfigInterface
{
    public function loadConfig($config_file);

    public function loadFile($file_name);
}