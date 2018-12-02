#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Sumac\Console\Command\Sync\RemoveDuplicatesCommand;
use Sumac\Console\Command\Sync\SyncCommand;
use Sumac\Console\Command\Notify\FindUnattendedTasks;
use Sumac\Console\Command\Sync\FindDuplicatesCommand;

$application = new Application();
$application->add(new FindDuplicatesCommand());
$application->add(new RemoveDuplicatesCommand());
$application->add(new SyncCommand());
$application->add(new FindUnattendedTasks());
$application->run();
