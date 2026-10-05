<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Conversation;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteractionDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new FileStorage(\dirname(__DIR__) . '/.storage/input-history');

$sessionStore = new SessionStore($storage, 'demo-user');
$session = $sessionStore->create();

$inputHistory = new InputHistory($storage);

$conversation = new Conversation($agent, $session);
$conversation->setCommands(new Commands(new HelpCommand()));
$conversation->setInputHistory($inputHistory);

// Conversation records original inputs before processing or executing them.
foreach (['Explain PHP generators in one short sentence.', '/help'] as $input) {
    echo 'You: ' . $input . \PHP_EOL;
    $stream = $conversation->sendInput($input);
    foreach ($stream as $event) {
        if ($event instanceof TextChunk) {
            echo $event->content;
            \flush();
        } elseif ($event instanceof Notification) {
            echo $event->text . \PHP_EOL;
        }
    }
    echo \PHP_EOL;
}

// List the original inputs independently of Session messages.
echo '=== Recorded inputs ===' . \PHP_EOL;
foreach ($inputHistory->list() as $input) {
    echo 'You: ' . $input->getContent() . \PHP_EOL;
}
