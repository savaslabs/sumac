#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Sumac\Console\Command\Sync\SyncCommand;
use Sumac\Console\Command\Sync\DuplicatesCommand;

$application = new Application();
$application->add(new DuplicatesCommand());
$application->add(new SyncCommand());
$application->run();
