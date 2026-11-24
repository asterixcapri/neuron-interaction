<?php

declare(strict_types=1);

use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Subagents\Console\WorkCommand;
use NeuronInteraction\Subagents\Executor;
use NeuronInteraction\Subagents\SubagentStore;
use NeuronInteractionDemo\SubagentsRegistry;
use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$storage = new FileStorage(\dirname(__DIR__) . '/.storage/subagents');
$subagentStore = new SubagentStore($storage, new LockFactory(new SemaphoreStore()));
$executor = new Executor(SubagentsRegistry::create(), $subagentStore);

$console = new Application();
$console->addCommand(new WorkCommand($executor));
$console->run();
