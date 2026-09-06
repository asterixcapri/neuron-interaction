<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Examples\BackendAdapter;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$commands = new Commands(new LeaveCommand());
$sessionStore = new SessionStore(new InMemoryStorage(), 'demo-user');
$adapter = new BackendAdapter(new Agent(), $commands, $sessionStore, static function (): void {});

// The response contains stopped: true. The host decides how to end the interaction.
$response = $commands->run('/exit', new CommandArguments(), $adapter);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
