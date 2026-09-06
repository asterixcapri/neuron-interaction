<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Examples\BackendAdapter;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Seed a stored conversation so this example can run on its own.
$sessionStore = new SessionStore(new InMemoryStorage(), 'demo-user');
$session = $sessionStore->create();
$session->addMessage(new UserMessage('A conversation to reopen'));

$agent = new Agent();
$commands = new Commands(new ResumeCommand());
$adapter = new BackendAdapter($agent, $commands, $sessionStore, static function (): void {});

// A real route receives this key from the client.
$sessionKey = $session->getKey();
$commands->run('/resume', new CommandArguments($sessionKey), $adapter);

// Resume has installed the stored conversation as the Agent's History.
echo $agent->getChatHistory()->getMessages()[0]->getContent() . PHP_EOL;
