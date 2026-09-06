<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Examples\BackendAdapter;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$sessionStore = new SessionStore(new InMemoryStorage(), 'demo-user');

$previousSession = $sessionStore->create();
$previousSession->addMessage(new UserMessage('The previous conversation'));

$agent = new Agent();
$agent->setChatHistory($previousSession);

$commands = new Commands(new ClearCommand());
$adapter = new BackendAdapter($agent, $commands, $sessionStore, static function (): void {});
$commands->run('/clear', new CommandArguments(), $adapter);

// The Agent now has an empty Session; the previous conversation is still stored.
echo json_encode([
    'currentMessages' => $agent->getChatHistory()->getMessages(),
    'storedConversations' => count($sessionStore->summaries()),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
