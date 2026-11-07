<?php

declare(strict_types=1);

use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Subagents\Console\WorkCommand;
use NeuronInteraction\Subagents\Executor;
use NeuronInteraction\Subagents\SubagentStore;
use NeuronInteractionDemo\Subagents\Agents;
use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

// ConsoleAdapter starts this process with: subagents:work RUN_ID.
$storage = new FileStorage(\dirname(__DIR__) . '/.storage/subagents');
$locks = new LockFactory(new SemaphoreStore());
$runs = new SubagentStore($storage, $locks);
$executor = new Executor(Agents::registry(), $runs);

$console = new Application();
$console->addCommand(new WorkCommand($executor));
$console->run();
