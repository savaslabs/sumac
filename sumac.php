#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';

use Sumac\Console\Command\Sync\FindOrphansCommand;
use Sumac\Console\Command\Sync\CheckHarvestId;
use Symfony\Component\Console\Application;
use Sumac\Console\Command\Sync\RemoveDuplicatesCommand;
use Sumac\Console\Command\Sync\SyncCommand;
use Sumac\Console\Command\Sync\FindDuplicatesCommand;

$application = new Application();
$application->add(new FindDuplicatesCommand());
$application->add(new CheckHarvestId());
$application->add(new RemoveDuplicatesCommand());
$application->add(new FindOrphansCommand());
$application->add(new SyncCommand());
$application->run();
