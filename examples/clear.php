<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\Configuration;
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
$configuration = $configurationStore->read('global')
    ?? $configurationStore->create('global', ['agent' => 'demo']);
$factories = new AgentFactoryRegistry();
$factories->register('demo', static fn (Configuration $configuration): Agent => new Agent());

$previousSession = $sessionStore->create();
$previousSession->addMessage(new UserMessage('The previous conversation'));

$agent = $factories->create($configuration);
$agent->setChatHistory($previousSession);

$commands = new Commands(new ClearCommand());
$adapter = new BackendAdapter($agent, $commands, $sessionStore, static function (): void {}, $factories, $configurationStore);
$commands->run('/clear', new CommandArguments(), $adapter);

// The Agent now has an empty Session; the previous conversation is still stored.
echo json_encode([
    'currentMessages' => $adapter->agent()->getChatHistory()->getMessages(),
    'storedConversations' => count($sessionStore->summaries()),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
