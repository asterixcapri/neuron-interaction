<?php

declare(strict_types=1);

use NeuronInteraction\Examples\ConfiguredAgent;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Examples\BackendAdapter;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');
$configurationStore = new ConfigurationStore($storage, 'demo-user');
$configurationStore->read('agent')
    ?? $configurationStore->create('agent', ['model' => 'local', 'capability' => 'search']);
$agentFactoryRegistry = new AgentFactoryRegistry();
$agentFactoryRegistry->register('demo', ConfiguredAgent::class);

$previousSession = $sessionStore->create();
$previousSession->addMessage(new UserMessage('The previous conversation'));

$agent = $agentFactoryRegistry->create('demo', $configurationStore);
$agent->setChatHistory($previousSession);

$commands = new Commands(new ClearCommand());
$adapter = new BackendAdapter($agent, $commands, $sessionStore, static function (): void {}, $agentFactoryRegistry, $configurationStore);
$commands->run('/clear', new CommandArguments(), $adapter);

// The Agent now has an empty Session; the previous conversation is still stored.
echo json_encode([
    'currentMessages' => $adapter->agent()->getChatHistory()->getMessages(),
    'storedConversations' => count($sessionStore->summaries()),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
