<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use Closure;
use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Conversation;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Message\UserMessageProcessorInterface;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function count;
use function iterator_to_array;

final class CommandPromptTest extends TestCase
{
    public function testOrderedPromptsForwardNativeEventsAndReturnLastState(): void
    {
        $first = new TextChunk('first', 'First');
        $second = new TextChunk('second', 'Second');
        $states = [new AgentState(), new AgentState()];
        $calls = [];
        $conversation = $this->conversation(
            static function (CommandContext $context): void {
                $context->notify('before');
                $context->promptAgent(new UserMessage('one'));
                $context->notify('between');
                $context->promptAgent(new UserMessage('two'));
                $context->notify('after');
            },
            static function (Message|array $message) use (&$calls, $states, $first, $second): Generator {
                self::assertInstanceOf(UserMessage::class, $message);
                $calls[] = $message->getContent();
                $index = count($calls) - 1;
                yield 17 => [$first, $second][$index];
                return $states[$index];
            },
        );
        $stream = $conversation->sendInput('/prompt');
        self::assertSame([], $calls);
        $events = iterator_to_array($stream, false);
        self::assertCount(5, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame('before', $events[0]->text);
        self::assertSame($first, $events[1]);
        self::assertInstanceOf(Notification::class, $events[2]);
        self::assertSame('between', $events[2]->text);
        self::assertSame($second, $events[3]);
        self::assertInstanceOf(Notification::class, $events[4]);
        self::assertSame('after', $events[4]->text);
        self::assertSame(['one', 'two'], $calls);
        self::assertSame($states[1], $stream->getReturn());
    }

    public function testPromptsPrepareAtTheirPositionOnceAndPreserveMetadataAndAttachments(): void
    {
        $message = new UserMessage(new ImageContent('https://example.com/image.png', SourceType::URL));
        $message->addMetadata('origin', 'command');
        $processor = new class implements UserMessageProcessorInterface {
            public int $calls = 0;
            public function forAgent(UserMessage $input): UserMessage
            {
                ++$this->calls;
                $input->addMetadata('processed', true);
                return $input;
            }
            public function forDisplay(UserMessage $input): UserMessage
            {
                return $input;
            }
        };
        $conversation = $this->conversation(
            static function (CommandContext $context) use ($message): void {
                $context->notify('before');
                $context->promptAgent($message);
            },
            static function (Message|array $input) use ($message): Generator {
                self::assertInstanceOf(UserMessage::class, $input);
                self::assertNotSame($message, $input);
                self::assertEquals($message->getContentBlocks(), $input->getContentBlocks());
                self::assertSame('command', $input->getMetadata('origin'));
                self::assertTrue($input->getMetadata('processed'));
                yield 42 => new TextChunk('response', 'Image');
                return new AgentState();
            },
            $processor,
        );
        $stream = $conversation->sendInput('/prompt');
        $stream->rewind();
        self::assertSame(0, $processor->calls);
        $stream->next();
        self::assertSame(42, $stream->key());
        self::assertSame(1, $processor->calls);
        self::assertNull($message->getMetadata('processed'));
        $stream->next();
        self::assertFalse($stream->valid());
    }

    public function testRegisteredPromptRunsBeforeOriginalCommandFailure(): void
    {
        $failure = new RuntimeException('Command failed');
        $event = new TextChunk('response', 'Done');
        $conversation = $this->conversation(
            static function (CommandContext $context) use ($failure): void {
                $context->promptAgent(new UserMessage('one'));
                throw $failure;
            },
            static function () use ($event): Generator {
                yield $event;
                return new AgentState();
            },
        );
        $stream = $conversation->sendInput('/prompt');
        $stream->rewind();
        self::assertSame($event, $stream->current());
        try {
            $stream->next();
            self::fail('Expected Command failure');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testRequestFailureStopsRemainingRequestsWithoutRetryAndOverridesCommandFailure(): void
    {
        $requestFailure = new RuntimeException('Agent failed');
        $calls = 0;
        $conversation = $this->conversation(
            static function (CommandContext $context): void {
                $context->promptAgent(new UserMessage('one'));
                $context->notify('must not appear');
                $context->promptAgent(new UserMessage('two'));
                throw new RuntimeException('Command failed');
            },
            static function () use ($requestFailure, &$calls): Generator {
                ++$calls;
                yield from [];
                throw $requestFailure;
            },
        );
        try {
            iterator_to_array($conversation->sendInput('/prompt'));
            self::fail('Expected Agent failure');
        } catch (RuntimeException $exception) {
            self::assertSame($requestFailure, $exception);
        }
        self::assertSame(1, $calls);
    }

    public function testNormalReturnAfterApprovalAndResponseStopContinuesSequence(): void
    {
        $approval = new ApprovalRequest('Approve');
        $calls = 0;
        $conversation = null;
        $conversation = $this->conversation(
            static function (CommandContext $context): void {
                $context->promptAgent(new UserMessage('one'));
                $context->promptAgent(new UserMessage('two'));
            },
            static function () use (&$calls, &$conversation, $approval): Generator {
                ++$calls;
                self::assertNotNull($conversation);
                self::assertTrue($conversation->requestInterruption());
                yield $approval;
                return new AgentState();
            },
            stopSignal: new StopSignal(new InMemoryStorage(), 'prompts'),
        );
        self::assertSame([$approval, $approval], iterator_to_array($conversation->sendInput('/prompt'), false));
        self::assertSame(2, $calls);
    }

    /**
     * @param Closure(CommandContext): void $run
     * @param Closure(Message|array<Message>): Generator<int, object, mixed, AgentState> $stream
     */
    private function conversation(Closure $run, Closure $stream, ?UserMessageProcessorInterface $processors = null, ?StopSignal $stopSignal = null): Conversation
    {
        $command = new class ($run) implements CommandInterface {
            /** @param Closure(CommandContext): void $run */
            public function __construct(private Closure $run) {}
            public function name(): string
            {
                return '/prompt';
            }
            public function describe(): string
            {
                return 'Prompt test';
            }
            public function run(CommandContext $context, string $value): void
            {
                ($this->run)($context);
            }
        };
        $agent = new class ($stream) extends Agent {
            /** @param Closure(Message|array<Message>): Generator<int, object, mixed, AgentState> $stream */
            public function __construct(private Closure $stream) {}
            /**
             * @param Message|Message[] $messages
             * @return Generator<int, object, mixed, AgentState>
             */
            public function stream(Message|array $messages = []): Generator
            {
                return yield from ($this->stream)($messages);
            }
        };
        $conversation = new Conversation($agent, (new SessionStore(new InMemoryStorage(), 'owner'))->create());
        if ($processors !== null) {
            $conversation->setUserMessageProcessors(new UserMessageProcessors($processors));
        }
        if ($stopSignal !== null) {
            $conversation->setStopSignal($stopSignal);
        }
        $conversation->setCommands(new Commands($command));
        return $conversation;
    }
}
