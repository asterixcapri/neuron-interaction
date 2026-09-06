<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Examples\BackendAdapter;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Mount one Command and return its help text as response data.
$commands = new Commands(new HelpCommand());
$sessionStore = new SessionStore(new InMemoryStorage(), 'demo-user');
$adapter = new BackendAdapter(new Agent(), $commands, $sessionStore, static function (): void {});

$response = $commands->run('/help', new CommandArguments(), $adapter);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
