<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');

$session = $sessionStore->create();
$conversation = new Conversation($agent, $session);

$input = 'Hello! Introduce yourself in one short sentence.';
echo 'You: ' . $input . \PHP_EOL;
echo 'Agent: ';

$stream = $conversation->sendInput($input);

foreach ($stream as $event) {
    if ($event instanceof TextChunk) {
        echo $event->content;
        \flush();
    }
}

echo \PHP_EOL;
