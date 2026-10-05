<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Conversation;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Message\UserMessageProcessorInterface;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function iterator_to_array;

final class ConversationInputHistoryTest extends TestCase
{
    public function testOriginalInputsAreRecordedBeforeProcessingAndGeneratedPromptsAreExcluded(): void
    {
        $storage = new InMemoryStorage();
        $history = new InputHistory($storage);
        $provider = new FakeAIProvider(new AssistantMessage('Answer'), new AssistantMessage('Summary'));
        $conversation = new Conversation((new Agent())->setAiProvider($provider), (new SessionStore($storage, 'owner'))->create());
        self::assertNull($conversation->inputHistory());
        $conversation->setInputHistory($history);
        self::assertSame($history, $conversation->inputHistory());
        $processor = new class implements UserMessageProcessorInterface {
            public function forAgent(UserMessage $message): UserMessage
            {
                $message->setContents('Expanded: ' . $message->getContent());
                return $message;
            }
            public function forDisplay(UserMessage $message): UserMessage
            {
                return $message;
            }
        };
        $conversation->setUserMessageProcessors(new UserMessageProcessors($processor));
        $command = new class implements CommandInterface {
            public function name(): string
            {
                return '/summarize';
            }
            public function describe(): string
            {
                return 'Summarize';
            }
            public function run(CommandContext $context, string $value): void
            {
                $context->promptAgent(new UserMessage('Generated prompt'));
            }
        };
        $conversation->setCommands(new Commands($command));

        $original = new UserMessage([
            new TextContent('Read @README.md'),
            new ImageContent('https://example.com/photo.png', SourceType::URL, 'image/png'),
        ]);
        $original->addMetadata('source', 'human');
        $stream = $conversation->sendInput($original);
        $saved = $history->list()[0];
        self::assertSame('Read @README.md', $saved->getContent());
        self::assertSame('human', $saved->getMetadata('source'));
        self::assertInstanceOf(ImageContent::class, $saved->getContentBlocks()[1]);
        iterator_to_array($stream);

        $stream = $conversation->sendInput("/summarize\t  details");
        self::assertCount(2, $history->list());
        iterator_to_array($stream);
        iterator_to_array($conversation->sendInput(new CommandInput('/unknown', ' raw value ')));
        iterator_to_array($conversation->sendInput(" \n\t"));
        self::assertSame(
            ['Read @README.md', "/summarize\t  details", '/unknown  raw value '],
            array_map(static fn(UserMessage $message): ?string => $message->getContent(), $history->list()),
        );
        $messages = $conversation->getMessages();
        self::assertSame('Expanded: Read @README.md', $messages[0]->getContent());
        self::assertSame('Expanded: Generated prompt', $messages[2]->getContent());
    }

    public function testInputIsRetainedWhenPreparationFails(): void
    {
        $storage = new InMemoryStorage();
        $history = new InputHistory($storage);
        $conversation = new Conversation(new Agent(), (new SessionStore($storage, 'owner'))->create());
        $conversation->setInputHistory($history);
        $processor = $this->createStub(UserMessageProcessorInterface::class);
        $processor->method('forAgent')->willThrowException(new RuntimeException('Cannot expand'));
        $conversation->setUserMessageProcessors(new UserMessageProcessors($processor));

        try {
            $conversation->sendInput('Original input');
            self::fail('Expected preparation to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Cannot expand', $exception->getMessage());
        }
        self::assertSame('Original input', $history->list()[0]->getContent());
        self::assertSame([], $conversation->getMessages());
    }
}
