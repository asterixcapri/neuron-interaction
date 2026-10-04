<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteractionDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new FileStorage(\dirname(__DIR__) . '/.storage/multiple-sessions');
$sessionStore = new SessionStore($storage, 'demo-user');

$lisbonSession = $sessionStore->create();
$lisbonSession->setTitle('Trip to Lisbon');
$lisbonKey = $lisbonSession->getKey();

$conversation = new Conversation($agent, $lisbonSession);

echo '=== Lisbon Session ===' . \PHP_EOL;
execTurn($conversation, 'My destination is Lisbon. Acknowledge in one short sentence.');

// Creating another Session does not activate it: useSession() selects it.
$kyotoSession = $sessionStore->create();
$kyotoSession->setTitle('Trip to Kyoto');
$kyotoKey = $kyotoSession->getKey();

$conversation->useSession($kyotoSession);

echo '=== Kyoto Session ===' . \PHP_EOL;
execTurn($conversation, 'My destination is Kyoto. Acknowledge in one short sentence.');

// List this user's non-empty Sessions, most recently used first.
// FileStorage keeps them across runs; each run adds two more Sessions.
echo '=== Saved Sessions (key — title) ===' . \PHP_EOL;
foreach ($sessionStore->list() as $summary) {
    echo $summary->getKey() . ' — ' . $summary->getTitle() . \PHP_EOL;
}
echo \PHP_EOL;

// Any key from the list can be used to retrieve and resume its Session.
$conversation->useSession($sessionStore->get($lisbonKey));

echo '=== Back to Lisbon: saved messages ===' . \PHP_EOL;
foreach ($lisbonSession->getMessages() as $message) {
    echo \ucfirst($message->getRole()) . ': ' . $message->getContent() . \PHP_EOL;
}

// The Agent now uses Lisbon's History and should answer "Lisbon".
execTurn($conversation, 'What is my destination? Answer with just the city name.');

$conversation->useSession($sessionStore->get($kyotoKey));

echo '=== Back to Kyoto: saved messages ===' . \PHP_EOL;
foreach ($kyotoSession->getMessages() as $message) {
    echo \ucfirst($message->getRole()) . ': ' . $message->getContent() . \PHP_EOL;
}

// The Agent now uses Kyoto's History and should answer "Kyoto".
execTurn($conversation, 'What is my destination? Answer with just the city name.');

// Session operations stay visible above; this helper sends and prints one turn.
// Consuming the stream executes the Agent and saves the exchange automatically.
function execTurn(Conversation $conversation, string $input): void
{
    echo 'You: ' . $input . \PHP_EOL;
    echo 'Agent: ';

    $stream = $conversation->sendInput($input);

    foreach ($stream as $event) {
        if ($event instanceof TextChunk) {
            echo $event->content;
            \flush();
        }
    }

    echo \PHP_EOL . \PHP_EOL;
}
