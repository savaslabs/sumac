#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Sumac\Console\Command\SyncCommand;

$application = new Application();
$application->add(new SyncCommand());
$application->run();
