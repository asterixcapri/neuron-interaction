<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Examples\ConfiguredAgent;
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
    ?? $configurationStore->create('global', ['agent' => 'demo', 'model' => 'local', 'capability' => 'search']);
$agentFactoryRegistry = new AgentFactoryRegistry();
// This local provider makes the example runnable without credentials or network.
// Production factories can capture the application's provider clients instead.
$providerFactory = static fn (string $model, string $capability): AIProviderInterface =>
    new FakeAIProvider(new AssistantMessage($model . ':' . $capability));
$agentFactoryRegistry->register('demo', static function (Configuration $configuration) use ($providerFactory): Agent {
    $model = $configuration->get('model');
    $capability = $configuration->get('capability');
    if (!is_string($model) || !is_string($capability)) {
        throw new InvalidArgumentException('Model and capability must be strings.');
    }

    return (new ConfiguredAgent($providerFactory))->configure($model, $capability);
});

$previousSession = $sessionStore->create();
$previousSession->addMessage(new UserMessage('The previous conversation'));

$agent = $agentFactoryRegistry->create($configuration);
$agent->setChatHistory($previousSession);

$commands = new Commands(new ClearCommand());
$adapter = new BackendAdapter($agent, $commands, $sessionStore, static function (): void {}, $agentFactoryRegistry, $configurationStore);
$commands->run('/clear', new CommandArguments(), $adapter);

// The Agent now has an empty Session; the previous conversation is still stored.
echo json_encode([
    'currentMessages' => $adapter->agent()->getChatHistory()->getMessages(),
    'storedConversations' => count($sessionStore->summaries()),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
