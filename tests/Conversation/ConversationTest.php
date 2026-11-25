<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use Closure;
use Generator;
use NeuronAI\Agent\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Exception\ExecutionAbortedException;
use NeuronInteraction\Exception\ExecutionBusyException;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\EventCollector;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

use function array_map;
use function array_values;
use function ini_set;

final class ConversationTest extends TestCase
{
    public function testNativeObjectsSequenceAndAgentStatePassThroughUnchanged(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $tool = new ToolCall('lookup', 'call', ['q' => 'example']);
        $chunks = [7 => new TextChunk('message', 'Hello'), 12 => new ReasoningChunk('reasoning', 'Thinking'), 15 => new StepStartedStreamEvent('lookup'), 20 => new ToolCallChunk('message', $tool), 21 => new ToolResultChunk($tool)];
        $state = new AgentState();
        $agent = new class ($chunks, $state) extends Agent {
            /** @param array<int, object> $chunks */
            public function __construct(private array $chunks, private AgentState $result) {}
            /**
             * @param Message|Message[] $messages
             * @return Generator<int, object, mixed, AgentState>
             */
            public function stream(Message|array $messages = []): Generator
            {
                yield from $this->chunks;
                return $this->result;
            }
        };
        $conversation = new Conversation($agent, (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create());
        $events = EventCollector::input($conversation, new UserMessage('Hello'), $result);
        self::assertSame(array_values($chunks), $events);
        self::assertSame($state, $result);
    }

    public function testRunInputCompletesExecutionBeforeReturning(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('One'), new AssistantMessage('Two'));
        $conversation = new Conversation((new Agent())->setAiProvider($provider), (new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'local'))->create());
        EventCollector::input($conversation, 'First');
        self::assertCount(1, $provider->getRecorded());
        self::assertCount(2, $conversation->getMessages());
        EventCollector::input($conversation, 'Second');
        self::assertCount(2, $provider->getRecorded());
        self::assertSame('Second', $conversation->getMessages()[2]->getContent());
    }

    public function testRunInputStreamsAndSavesBeforeReleasingTheSessionLock(): void
    {
        $session = (new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'local'))->create();
        $locks = new LockFactory(new InMemoryStore());
        $contender = new Conversation($this->agent('Next answer'), $session, lockFactory: $locks);
        $steps = [];
        $conversation = new Conversation(
            $this->agent('Answer'),
            $session,
            lockFactory: $locks,
            transactional: static function (Closure $run) use ($session, $contender, &$steps) {
                $steps[] = 'begin';
                $state = $run();
                self::assertTrue($session->hasContent());
                try {
                    EventCollector::input($contender, 'Next question');
                    self::fail('The session must remain locked until commit.');
                } catch (ExecutionBusyException) {
                    $steps[] = 'commit';
                }
                return $state;
            },
        );

        $text = '';
        $conversation->runInput(new UserMessage('Question'), static function (object $event) use (&$steps, &$text): void {
            self::assertSame(['begin'], $steps);
            if ($event instanceof TextChunk) {
                $text .= $event->content;
            }
        });

        self::assertSame('Answer', $text);
        self::assertSame(['begin', 'commit'], $steps);
        EventCollector::input($contender, 'Next question');
        self::assertCount(4, $session->getMessages());
    }

    public function testCommandLockEndsBeforeEventsAndPromptOwnsItsTransactionAndLock(): void
    {
        $session = (new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'local'))->create();
        $locks = new LockFactory(new InMemoryStore());
        $contender = new Conversation($this->agent('Between'), $session, lockFactory: $locks);
        $command = new class ($contender) implements CommandInterface {
            public function __construct(private Conversation $contender) {}
            public function name(): string
            {
                return '/prompt';
            }
            public function describe(): string
            {
                return 'Prompt after notification';
            }
            public function run(CommandContext $context, string $value): void
            {
                try {
                    EventCollector::input($this->contender, 'Too early');
                    ConversationTest::fail('The command handler must hold the session lock.');
                } catch (ExecutionBusyException) {
                }
                $context->notify('Before prompt');
                $context->promptAgent(new UserMessage('Question'));
            }
        };
        $steps = [];
        $conversation = new Conversation($this->agent('Answer'), $session, lockFactory: $locks, transactional: static function (Closure $run) use (&$steps) {
            $steps[] = 'begin';
            $state = $run();
            $steps[] = 'commit';
            return $state;
        });
        $conversation->setCommands(new Commands($command));
        $state = $conversation->runInput('/prompt', static function (object $event) use ($contender, &$steps): void {
            if ($event instanceof Notification) {
                self::assertSame([], $steps);
                EventCollector::input($contender, 'Between command and prompt');
                $steps[] = 'notice';
                return;
            }
            self::assertSame(['notice', 'begin'], $steps);
            try {
                EventCollector::input($contender, 'During prompt');
                self::fail('The prompt must hold its own session lock.');
            } catch (ExecutionBusyException) {
            }
        });
        self::assertInstanceOf(AgentState::class, $state);
        self::assertSame('Answer', $state->getMessage()?->getContent());
        self::assertSame(['notice', 'begin', 'commit'], $steps);
        self::assertCount(4, $session->getMessages());
    }

    public function testReentrantInputIsRejectedUntilTheCallbackReturns(): void
    {
        $conversation = new Conversation($this->agent('First', 'Second'), (new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'local'))->create(), lockFactory: new LockFactory(new InMemoryStore()));
        $attempted = false;
        $conversation->runInput('First', static function (object $event) use ($conversation, &$attempted): void {
            if ($attempted) {
                return;
            }
            $attempted = true;
            try {
                EventCollector::input($conversation, 'Overlapping');
                self::fail('Expected the active turn to reject reentrant input.');
            } catch (ExecutionBusyException) {
            }
        });
        self::assertTrue($attempted);
        EventCollector::input($conversation, 'Second');
        self::assertCount(4, $conversation->getMessages());
    }

    public function testCallbackAbortClosesTheNativeStreamBeforeRollbackAndLockRelease(): void
    {
        $steps = [];
        $session = (new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'local'))->create();
        $locks = new LockFactory(new InMemoryStore());
        $contender = new Conversation($this->agent('Next'), $session, lockFactory: $locks);
        $agent = new class (static function () use (&$steps): void {
            $steps[] = 'closed';
        }, $contender) extends Agent {
            /** @param Closure(): void $onClose */
            public function __construct(private Closure $onClose, private Conversation $contender) {}
            /**
             * @param Message|Message[] $messages
             * @return Generator<int, object, mixed, AgentState>
             */
            public function stream(Message|array $messages = []): Generator
            {
                try {
                    yield new TextChunk('partial', 'Partial');
                    ConversationTest::fail('The abandoned stream must never resume.');
                } finally {
                    ($this->onClose)();
                    try {
                        EventCollector::input($this->contender, 'Too early');
                        ConversationTest::fail('The lock must still be held while closing the stream.');
                    } catch (ExecutionBusyException) {
                    }
                }
            }
        };
        $conversation = new Conversation($agent, $session, lockFactory: $locks, transactional: static function (Closure $run) use (&$steps) {
            try {
                return $run();
            } catch (RuntimeException $error) {
                $steps[] = 'rollback';
                self::assertSame(['callback', 'closed', 'rollback'], $steps);
                throw $error;
            }
        });
        $traceArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            $conversation->runInput('Question', static function (object $event) use (&$steps): void {
                $steps[] = 'callback';
                throw new ExecutionAbortedException('Caller aborted');
            });
            self::fail('Expected the callback failure.');
        } catch (ExecutionAbortedException $error) {
            self::assertSame('Caller aborted', $error->getMessage());
            self::assertSame(['callback', 'closed', 'rollback'], $steps);
            EventCollector::input($contender, 'Next');
        } finally {
            if ($traceArgs !== false) {
                ini_set('zend.exception_ignore_args', $traceArgs);
            }
        }
    }

    public function testFailurePropagatesAfterPartialTextAndReleasesWithoutRetry(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $failure = new RuntimeException('Provider failed');
        $provider = new class ($failure) extends FakeAIProvider {
            public function __construct(private RuntimeException $failure)
            {
                parent::__construct(new AssistantMessage('Unused'), new AssistantMessage('Recovered'));
            }
            protected function streamChunks(Message $response): Generator
            {
                if ($response->getContent() === 'Unused') {
                    yield new TextChunk('partial', 'Partial answer');
                    throw $this->failure;
                }
                yield new TextChunk('next', $response->getContent() ?? '');
                return new ProviderResponse(message: $response);
            }
        };
        $conversation = new Conversation((new Agent())->setAiProvider($provider), (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create());
        $text = '';
        try {
            $conversation->runInput(new UserMessage('Fail'), static function (object $chunk) use (&$text): void {
                if ($chunk instanceof TextChunk) {
                    $text .= $chunk->content;
                }
            });
            self::fail('Expected failure');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertSame('Partial answer', $text);
        self::assertFalse($conversation->session()->hasContent());
        self::assertEquals($conversation->session()->getCreatedAt(), $conversation->session()->getLastUsedAt());
        EventCollector::input($conversation, new UserMessage('Continue'), $next);
        self::assertInstanceOf(AgentState::class, $next);
        self::assertSame('Recovered', $next->getMessage()?->getContent());
        self::assertCount(2, $provider->getRecorded());
    }

    public function testExecutionRetainsItsAgentAndSessionAcrossCallbackReplacement(): void
    {
        $store = new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'owner');
        $original = $store->create();
        $selected = $store->create();
        $conversation = new Conversation($this->agent('Selected response'), $selected);
        $replaced = false;
        $state = $conversation->runInput('Question', function (object $event) use ($conversation, $original, &$replaced): void {
            if (!$replaced) {
                $replaced = true;
                $conversation->useSession($original);
                $conversation->useAgent($this->agent('Replacement'));
            }
        });
        self::assertInstanceOf(AgentState::class, $state);
        self::assertSame('Selected response', $state->getMessage()?->getContent());
        self::assertSame([], $conversation->agent()->getChatHistory()->getMessages());
        self::assertCount(2, $selected->getMessages());
        self::assertSame($original, $conversation->session());
        $conversation->useSession($selected);
        self::assertCount(2, $conversation->agent()->getChatHistory()->getMessages());
    }

    public function testConsumedResponseStopResetsAtNextExecution(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'stop');
        $conversation = new Conversation($this->agent('Partial', 'Next'), (new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'local'))->create());
        $conversation->setStopSignal($signal);
        $requested = false;
        $conversation->runInput('Stop', static function (object $event) use ($conversation, $signal, &$requested): void {
            if ($requested) {
                return;
            }
            $requested = true;
            self::assertTrue($conversation->requestInterruption());
            self::assertFalse($conversation->requestInterruption());
            self::assertFalse($conversation->responseWasStopped());
            self::assertTrue(($signal->stopCallback(pollInterval: 0))());
            self::assertTrue($conversation->responseWasStopped());
        });
        self::assertTrue($conversation->responseStopRequested());
        $conversation->runInput('Next', static function (object $event) use ($conversation): void {
            self::assertFalse($conversation->responseStopRequested());
            self::assertFalse($conversation->responseWasStopped());
        });
    }

    public function testPendingAndStaleStopRequestsAreNotReportedAsConsumed(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'pending');
        $signal->request();
        $conversation = new Conversation($this->agent('Completed', 'Next'), (new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'local'))->create());
        $conversation->setStopSignal($signal);
        $requested = false;
        $conversation->runInput('Question', static function (object $event) use ($conversation, $signal, &$requested): void {
            if (!$requested) {
                self::assertFalse($signal->isRequested());
                self::assertTrue($conversation->requestInterruption());
                $requested = true;
            }
        });
        self::assertTrue($conversation->responseStopRequested());
        self::assertFalse($conversation->responseWasStopped());
        EventCollector::input($conversation, 'Next');
        self::assertFalse($signal->isRequested());
        self::assertFalse($conversation->responseStopRequested());
    }

    public function testStopCanBeRequestedWithoutALocalExecutionAndIsClearedAtStreamStart(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $signal = new StopSignal(new InMemoryStorage(), 'idle-stop');
        $conversation = new Conversation($this->agent('Answer'), (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create());
        $conversation->setStopSignal($signal);
        self::assertTrue($conversation->requestInterruption());
        self::assertTrue($signal->isRequested());
        self::assertTrue($conversation->responseStopRequested());
        self::assertFalse($conversation->requestInterruption());

        $conversation->runInput('Question', static function (object $event) use ($conversation, $signal): void {
            self::assertFalse($signal->isRequested());
            self::assertFalse($conversation->responseStopRequested());
        });
        self::assertFalse((new Conversation($this->agent('No stop'), (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create()))->requestInterruption());
    }

    public function testConstructionUsesTheSuppliedSessionWithoutCreatingAnother(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $session = (new SessionStore($storage, $nativeMessages, 'owner'))->create();
        $conversation = new Conversation($this->agent('Answer'), $session);

        self::assertSame($session, $conversation->session());
        self::assertSame($session->messageNamespace(), $conversation->agent()->getThreadId());
        self::assertCount(1, $storage->list(Session::namespaceForUser('owner')));
    }

    public function testHostCanSelectASessionFromAnotherStore(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $initial = (new SessionStore($storage, $nativeMessages, 'owner'))->create();
        $selected = (new SessionStore($storage, $nativeMessages, 'other'))->create();
        $conversation = new Conversation($this->agent('Answer'), $initial);
        $conversation->useSession($selected);

        self::assertSame($selected, $conversation->session());
        EventCollector::input($conversation, 'Question');
        self::assertSame('Answer', $selected->getMessages()[1]->getContent());
        self::assertSame([], $initial->getMessages());
    }

    public function testSuppliedSessionReplacesAnAgentsPreviousHistory(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $agent = $this->agent('Answer')->setThreadId('existing');
        $agent->getChatHistory()->addMessage(new UserMessage('Unrelated history'));
        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create();
        $conversation = new Conversation($agent, $session);

        self::assertSame([], $conversation->agent()->getChatHistory()->getMessages());
        self::assertSame($session->messageNamespace(), $conversation->agent()->getThreadId());
    }

    public function testFailedTurnDoesNotUpdateTheRecordEvenWhenTheInputWasSaved(): void
    {
        $native = new InMemoryMessageStore();
        $messages = $this->createStub(MessageStoreInterface::class);
        $messages->method('loadActive')->willReturnCallback($native->loadActive(...));
        $messages->method('loadAll')->willReturnCallback($native->loadAll(...));
        $messages->method('append')->willReturnCallback(static function (string $thread, Message $message) use ($native): void {
            if ($message instanceof AssistantMessage) {
                throw new RuntimeException('Answer persistence failed');
            }
            $native->append($thread, $message);
        });
        $session = (new SessionStore(new InMemoryStorage(), $messages, 'owner'))->create();
        $conversation = new Conversation($this->agent('Answer'), $session);
        try {
            EventCollector::input($conversation, 'Question');
            self::fail('Expected the persistence failure.');
        } catch (RuntimeException $error) {
            self::assertSame('Answer persistence failed', $error->getMessage());
        }
        self::assertFalse($session->hasContent());
        self::assertEquals($session->getCreatedAt(), $session->getLastUsedAt());
        self::assertCount(1, $session->getMessages());
        self::assertSame('Question', $session->getMessages()[0]->getContent());
    }

    public function testDirectAgentUseOutsideAConversationTurnDoesNotUpdateTheRecord(): void
    {
        $session = (new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'owner'))->create();
        $conversation = new Conversation($this->agent('Answer'), $session);
        $before = $session->getLastUsedAt();
        $conversation->agent()->chat(new UserMessage('Question'));
        self::assertCount(2, $session->getMessages());
        self::assertFalse($session->hasContent());
        self::assertEquals($before, $session->getLastUsedAt());
    }

    private function agent(string ...$answers): Agent
    {
        return (new Agent())->setAiProvider(new FakeAIProvider(...array_map(static fn(string $answer) => new AssistantMessage($answer), $answers)));
    }
}
