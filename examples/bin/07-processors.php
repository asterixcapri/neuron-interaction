<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Conversation;
use NeuronInteraction\Message\MessageProcessorInterface;
use NeuronInteraction\Message\MessageProcessors;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

/** Adds file contents in separate blocks, keeping the person's text intact. */
final readonly class FileMessageProcessor implements MessageProcessorInterface
{
    private const string FILE_KEY = 'demo:file';

    public function __construct(private string $directory) {}

    public function forAgent(UserMessage $message): UserMessage
    {
        $prepared = clone $message;
        $loaded = [];
        foreach ($prepared->getContentBlocks() as $block) {
            if ($block instanceof TextContent && \is_string($name = $block->getMetadata(self::FILE_KEY))) {
                $loaded[$name] = true;
            }
        }
        foreach ($prepared->getContentBlocks() as $block) {
            if (!$block instanceof TextContent || $block instanceof ReasoningContent
                || \is_string($block->getMetadata(self::FILE_KEY))) {
                continue;
            }
            \preg_match_all('~(?<!\S)@([A-Za-z0-9_./-]+)(?=\s|$)~', $block->content, $matches);
            foreach ($matches[1] as $name) {
                if (isset($loaded[$name])) {
                    continue;
                }
                $loaded[$name] = true;
                $path = \realpath($this->directory . '/' . $name);
                if ($path === false || !\str_starts_with($path, $this->directory . '/') || !\is_file($path)) {
                    throw new RuntimeException("File {$name} does not exist in the configured directory.");
                }
                $contents = \file_get_contents($path);
                if ($contents === false) {
                    throw new RuntimeException("File {$name} could not be read.");
                }
                $prepared->addContent((new TextContent("File: {$name}\n\n{$contents}"))->addMetadata(self::FILE_KEY, $name));
            }
        }

        return $prepared;
    }

    public function forDisplay(UserMessage $message): UserMessage
    {
        $display = clone $message;
        $blocks = [];
        foreach ($display->getContentBlocks() as $block) {
            if (!$block instanceof TextContent || !\is_string($block->getMetadata(self::FILE_KEY))) {
                $blocks[] = $block;
            }
        }
        $display->setContents($blocks);

        return $display;
    }
}

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new InMemoryStorage();
$messageStore = new InMemoryMessageStore();
$sessionStore = new SessionStore($storage, $messageStore, 'demo-user');

$session = $sessionStore->create();
$session->setTitle('A README summary');

$conversation = new Conversation($agent, $session);

$conversation->setMessageProcessors(new MessageProcessors(
    new FileMessageProcessor(\dirname(__DIR__, 2))
));

// Conversation adds the README contents before executing the Agent.
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
