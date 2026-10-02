<?php

declare(strict_types=1);

use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteractionDemo\AIProviderFactory;
use NeuronInteractionDemo\DemoAgent;
use Symfony\Component\Dotenv\Dotenv;

use function NeuronInteractionDemo\execTurn;
use function NeuronInteractionDemo\showMessages;
use function NeuronInteractionDemo\showSessions;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new FileStorage(\dirname(__DIR__) . '/.storage/multiple-sessions');
$sessionStore = new SessionStore($storage, 'demo-user');

$conversation = new Conversation($agent, $sessionStore);

$session1 = $conversation->session();
$session1->setTitle('Trip to Lisbon');

// Each exchange is streamed to the terminal and saved automatically.
echo '=== Lisbon Session ===' . \PHP_EOL;
execTurn($conversation, 'My destination is Lisbon. Acknowledge in one short sentence.');

$session2 = $sessionStore->create();
$session2->setTitle('Trip to Kyoto');

$conversation->useSession($session2);

echo '=== Kyoto Session ===' . \PHP_EOL;
execTurn($conversation, 'My destination is Kyoto');

// The Store lists this user's non-empty Sessions, most recently used first.

showSessions($sessionStore);

// Select a saved Session by key. Conversation restores its History for the Agent.
$firstSession = $sessionStore->get($session1->getKey())
    ?? throw new RuntimeException('Lisbon Session not found.');

echo '=== Back to the Lisbon Session ===' . \PHP_EOL;

$conversation->useSession($firstSession);
showMessages($firstSession);
execTurn($conversation, 'What is my destination?');

// Select the kyoto session
$secondSession = $sessionStore->get($session2->getKey())
    ?? throw new RuntimeException('Lisbon Session not found.');

echo '=== Back to the Kyoto Session ===' . \PHP_EOL;

$conversation->useSession($secondSession);
showMessages($secondSession);
execTurn($conversation, 'What is my destination?');

echo 'Lisbon Session: ' . \count($session1->getMessages()) . ' saved messages' . \PHP_EOL;
echo 'Kyoto Session: ' . \count($session2->getMessages()) . ' saved messages' . \PHP_EOL;
