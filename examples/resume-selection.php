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

// Seed two conversations for the frontend to offer.
$sessionStore = new SessionStore(new InMemoryStorage(), 'demo-user');

$firstSession = $sessionStore->create();
$firstSession->addMessage(new UserMessage('Planning a trip'));

$sessionStore->create()->addMessage(new UserMessage('Learning PHP'));
$commands = new Commands(new ResumeCommand());

// Request 1: /resume without a key returns selection.options for the frontend.
$firstRequest = new BackendAdapter(new Agent(), $commands, $sessionStore, static function (): void {});
$response = $commands->run('/resume', new CommandArguments(), $firstRequest);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

// The user chooses "Planning a trip". The frontend sends that option's value.
$sessionKey = $firstSession->getKey();

// Request 2: a fresh Agent and Adapter receive /resume with the chosen key.
$agent = new Agent();
$secondRequest = new BackendAdapter($agent, $commands, $sessionStore, static function (): void {});
$commands->run('/resume', new CommandArguments($sessionKey), $secondRequest);

echo $agent->getChatHistory()->getMessages()[0]->getContent() . PHP_EOL;
