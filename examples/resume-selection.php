<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Examples\BackendAdapter;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Seed two conversations for the frontend to offer.
$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');
$configurationStore = new ConfigurationStore($storage, 'demo-user');

$firstSession = $sessionStore->create();
$firstSession->bindTo(new Agent())->getChatHistory()->addMessage(new UserMessage('Planning a trip'));
$firstSession->setTitle('Planning a trip');

$secondSession = $sessionStore->create();
$secondSession->bindTo(new Agent())->getChatHistory()->addMessage(new UserMessage('Learning PHP'));
$secondSession->setTitle('Learning PHP');
$commands = (new Commands())->addCommand(new ResumeCommand());

// Request 1: /resume without a key returns selection.options for the frontend.
$firstRequest = new BackendAdapter((new Agent())->setThreadId('test-thread'), $commands, $sessionStore, static function (): void {}, configurationStore: $configurationStore);
$response = $commands->run('/resume', '', $firstRequest);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

// The user chooses "Planning a trip". The frontend sends that option's value.
$sessionKey = $firstSession->getKey();

// Request 2: a fresh Agent and Adapter receive /resume with the chosen key.
$agent = (new Agent())->setThreadId('test-thread');
$secondRequest = new BackendAdapter($agent, $commands, $sessionStore, static function (): void {}, configurationStore: $configurationStore);
$commands->run('/resume', $sessionKey, $secondRequest);

echo $secondRequest->agent()->getChatHistory()->getMessages()[0]->getContent() . PHP_EOL;
