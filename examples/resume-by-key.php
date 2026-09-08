<?php

declare(strict_types=1);

use NeuronInteraction\Examples\ConfiguredAgent;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Examples\BackendAdapter;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Seed a stored conversation so this example can run on its own.
$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');
$configurationStore = new ConfigurationStore($storage, 'demo-user');

$configurationStore->read('agent')
    ?? $configurationStore->create('agent', ['model' => 'local', 'capability' => 'search']);

$agentFactoryRegistry = new AgentFactoryRegistry();

$agentFactoryRegistry->register('demo', ConfiguredAgent::class);

$session = $sessionStore->create();
$session->addMessage(new UserMessage('A conversation to reopen'));

$agent = $agentFactoryRegistry->create('demo', $configurationStore);
$commands = new Commands(new ResumeCommand());
$adapter = new BackendAdapter($agent, $commands, $sessionStore, static function (): void {}, $agentFactoryRegistry, $configurationStore);

// A real route receives this key from the client.
$sessionKey = $session->getKey();
$commands->run('/resume', new CommandArguments($sessionKey), $adapter);

// Resume has installed the stored conversation as the Agent's History.
echo $adapter->agent()->getChatHistory()->getMessages()[0]->getContent() . PHP_EOL;
