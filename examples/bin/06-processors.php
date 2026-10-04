<?php

declare(strict_types=1);

use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use NeuronInteractionDemo\DemoAgent;
use NeuronInteractionDemo\FileReferenceProcessor;
use Symfony\Component\Dotenv\Dotenv;

use function NeuronInteractionDemo\execTurn;
use function NeuronInteractionDemo\showMessages;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));
// File contents are supplied by the processor in this example.
$agent->setTools([]);

$processor = new FileReferenceProcessor(\dirname(__DIR__) . '/fixtures');
$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');
$session = $sessionStore->create();
$conversation = new Conversation(
    $agent,
    $session,
    userMessageProcessors: $processor,
);
$conversation->session()->setTitle('A trip described in a file');

// Conversation expands the reference before executing the Agent.
execTurn($conversation, 'Summarize @trip.txt in one short sentence using the referenced file contents.');

echo '=== Saved messages include the file contents sent to the Agent ===' . \PHP_EOL;
showMessages($conversation->session());

$saved = $conversation->session()->getMessages()[0];
if (!$saved instanceof UserMessage) {
    throw new RuntimeException('Expected a saved user message.');
}
echo \PHP_EOL . '=== The display projection hides the expanded file ===' . \PHP_EOL;
echo 'You: ' . $processor->forDisplay($saved)->getContent() . \PHP_EOL;
