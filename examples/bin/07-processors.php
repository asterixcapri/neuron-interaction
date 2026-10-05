<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Conversation;
use NeuronInteraction\Message\AbstractUserMessageTagProcessor;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

/** Expands @file references into <file> tags with their text contents. */
final class FileUserMessageProcessor extends AbstractUserMessageTagProcessor
{
    public function __construct(private readonly string $directory) {}

    protected function tagName(): string
    {
        return 'file';
    }

    protected function contentFor(string $name): string
    {
        $path = \realpath($this->directory . '/' . $name);
        if ($path === false) {
            throw new RuntimeException("File {$name} does not exist.");
        }

        $contents = \file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("File {$name} could not be read.");
        }

        return $contents;
    }
}

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$processor = new FileUserMessageProcessor(\dirname(__DIR__, 2));

$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');

$session = $sessionStore->create();
$session->setTitle('A README summary');

$conversation = new Conversation($agent, $session);
$processors = new UserMessageProcessors($processor);
$conversation->setUserMessageProcessors($processors);

// Conversation expands @README.md before executing the Agent.
$input = 'Summarize @README.md in one short sentence.';
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

echo \PHP_EOL . '=== Session messages for display ===' . \PHP_EOL;
foreach ($conversation->getDisplayMessages() as $message) {
    echo \ucfirst($message->getRole()) . ': ' . $message->getContent() . \PHP_EOL;
}
