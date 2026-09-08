<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\Configuration;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Examples\BackendAdapter;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Seed two conversations for the frontend to offer.
$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');
$configurationStore = new ConfigurationStore($storage, 'demo-user');
$configuration = $configurationStore->read('global')
    ?? $configurationStore->create('global', ['agent' => 'demo']);
$factories = new AgentFactoryRegistry();
$factories->register('demo', static fn (Configuration $configuration): Agent => new Agent());

$firstSession = $sessionStore->create();
$firstSession->addMessage(new UserMessage('Planning a trip'));

$sessionStore->create()->addMessage(new UserMessage('Learning PHP'));
$commands = new Commands(new ResumeCommand());

// Request 1: /resume without a key returns selection.options for the frontend.
$firstRequest = new BackendAdapter($factories->create($configuration), $commands, $sessionStore, static function (): void {}, $factories, $configurationStore);
$response = $commands->run('/resume', new CommandArguments(), $firstRequest);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

// The user chooses "Planning a trip". The frontend sends that option's value.
$sessionKey = $firstSession->getKey();

// Request 2: a fresh Agent and Adapter receive /resume with the chosen key.
$agent = $factories->create($configuration);
$secondRequest = new BackendAdapter($agent, $commands, $sessionStore, static function (): void {}, $factories, $configurationStore);
$commands->run('/resume', new CommandArguments($sessionKey), $secondRequest);

echo $secondRequest->agent()->getChatHistory()->getMessages()[0]->getContent() . PHP_EOL;
