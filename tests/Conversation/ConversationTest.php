<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use Generator;
use InvalidArgumentException;
use NeuronAI\Agent\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
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
use NeuronInteraction\Conversation;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function iterator_to_array;

final class ConversationTest extends TestCase
{
    public function testNativeObjectsKeysAndAgentStatePassThroughUnchanged(): void
    {
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
        $conversation = new Conversation($agent, new SessionStore(new InMemoryStorage(), 'local'));
        $stream = $conversation->submitMessage(new UserMessage('Hello'));
        self::assertSame($chunks, iterator_to_array($stream));
        self::assertSame($state, $stream->getReturn());
    }

    public function testSubmissionPreparesButOnlyConsumptionExecutesAndUnstartedStreamDoesNotReserve(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('One'), new AssistantMessage('Two'));
        $conversation = new Conversation((new Agent())->setAiProvider($provider), new SessionStore(new InMemoryStorage(), 'local'));
        $stream = $conversation->submitMessage(new UserMessage('First'));
        $before = $provider->getRecorded();
        self::assertSame([], $before);
        $historyBefore = $conversation->agent()->getChatHistory()->getMessages();
        self::assertSame([], $historyBefore);
        unset($stream);
        iterator_to_array($conversation->submitMessage(new UserMessage('Second')));
        self::assertCount(1, $provider->getRecorded());
        self::assertSame('Second', $conversation->agent()->getChatHistory()->getMessages()[0]->getContent());
    }

    public function testRuntimeLeavesOverlappingStreamAdmissionToTheAgentOrHost(): void
    {
        $agent = new class extends Agent {
            /**
             * @param Message|Message[] $messages
             * @return Generator<int, object, mixed, AgentState>
             */
            public function stream(Message|array $messages = []): Generator
            {
                yield new TextChunk('response', $messages instanceof Message ? $messages->getContent() ?? '' : '');
                return new AgentState();
            }
        };
        $conversation = new Conversation($agent, new SessionStore(new InMemoryStorage(), 'local'));
        $first = $conversation->submitMessage(new UserMessage('First'));
        $second = $conversation->submitMessage(new UserMessage('Second'));
        $first->rewind();
        $second->rewind();
        $third = $conversation->submitMessage(new UserMessage('Third'));
        $third->rewind();

        foreach ([$first, $second, $third] as $index => $stream) {
            $chunk = $stream->current();
            self::assertInstanceOf(TextChunk::class, $chunk);
            self::assertSame(['First', 'Second', 'Third'][$index], $chunk->content);
            iterator_to_array($stream);
            self::assertInstanceOf(AgentState::class, $stream->getReturn());
        }
    }

    public function testDestroyingAPartiallyConsumedStreamReleasesNativeExecution(): void
    {
        $conversation = new Conversation($this->agent('Partial', 'Next'), new SessionStore(new InMemoryStorage(), 'local'));
        $stream = $conversation->submitMessage(new UserMessage('First'));
        foreach ($stream as $chunk) {
            self::assertInstanceOf(TextChunk::class, $chunk);
            break;
        }
        unset($stream);
        $next = $conversation->submitMessage(new UserMessage('Next'));
        iterator_to_array($next);
        self::assertSame('Next', $next->getReturn()->getMessage()?->getContent());
    }

    public function testFailurePropagatesAfterPartialTextAndReleasesWithoutRetry(): void
    {
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
        $conversation = new Conversation((new Agent())->setAiProvider($provider), new SessionStore(new InMemoryStorage(), 'local'));
        $text = '';
        try {
            foreach ($conversation->submitMessage(new UserMessage('Fail')) as $chunk) {
                if ($chunk instanceof TextChunk) {
                    $text .= $chunk->content;
                }
            }
            self::fail('Expected failure');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertSame('Partial answer', $text);
        $next = $conversation->submitMessage(new UserMessage('Continue'));
        iterator_to_array($next);
        self::assertSame('Recovered', $next->getReturn()->getMessage()?->getContent());
        self::assertCount(2, $provider->getRecorded());
    }

    public function testExecutionUsesCurrentAgentAndSessionThenRetainsThemAcrossReplacement(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'owner');
        $original = $store->create();
        $selected = $store->create();
        $conversation = new Conversation($this->agent('Original response'), $store, session: $original);
        $stream = $conversation->submitMessage(new UserMessage('Question'));
        $conversation->useSession($selected);
        $conversation->useAgent($this->agent('Selected response'));
        $stream->rewind();
        $conversation->useSession($original);
        $conversation->useAgent($this->agent('Replacement'));
        iterator_to_array($stream);
        self::assertSame('Selected response', $stream->getReturn()->getMessage()?->getContent());
        self::assertSame([], $conversation->agent()->getChatHistory()->getMessages());
        $saved = $store->read($selected->getKey());
        self::assertNotNull($saved);
        self::assertCount(2, $saved->getMessages());
        self::assertSame($original->getKey(), $conversation->session()->getKey());
        $conversation->useSession($selected);
        self::assertCount(2, $conversation->agent()->getChatHistory()->getMessages());
    }

    public function testConsumedResponseStopResetsAtNextExecution(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'stop');
        $conversation = new Conversation($this->agent('Partial', 'Next'), new SessionStore(new InMemoryStorage(), 'local'), stopSignal: $signal);
        $stream = $conversation->submitMessage(new UserMessage('Stop'));
        $stream->rewind();
        self::assertTrue($conversation->requestInterruption());
        self::assertFalse($conversation->requestInterruption());
        self::assertFalse($conversation->responseWasStopped());
        self::assertTrue(($signal->stopCallback(pollInterval: 0))());
        self::assertTrue($conversation->responseWasStopped());
        iterator_to_array($stream);
        self::assertTrue($conversation->responseStopRequested());
        $next = $conversation->submitMessage(new UserMessage('Next'));
        $next->rewind();
        self::assertFalse($conversation->responseStopRequested());
        self::assertFalse($conversation->responseWasStopped());
        iterator_to_array($next);
    }

    public function testPendingAndStaleStopRequestsAreNotReportedAsConsumed(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'pending');
        $signal->request();
        $conversation = new Conversation($this->agent('Completed', 'Next'), new SessionStore(new InMemoryStorage(), 'local'), stopSignal: $signal);
        $stream = $conversation->submitMessage(new UserMessage('Question'));
        $stream->rewind();
        self::assertFalse($signal->isRequested());
        self::assertTrue($conversation->requestInterruption());
        iterator_to_array($stream);
        self::assertTrue($conversation->responseStopRequested());
        self::assertFalse($conversation->responseWasStopped());
        iterator_to_array($conversation->submitMessage(new UserMessage('Next')));
        self::assertFalse($signal->isRequested());
        self::assertFalse($conversation->responseStopRequested());
    }

    public function testStopCanBeRequestedWithoutALocalExecutionAndIsClearedAtStreamStart(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'idle-stop');
        $conversation = new Conversation($this->agent('Answer'), new SessionStore(new InMemoryStorage(), 'local'), stopSignal: $signal);
        self::assertTrue($conversation->requestInterruption());
        self::assertTrue($signal->isRequested());
        self::assertTrue($conversation->responseStopRequested());
        self::assertFalse($conversation->requestInterruption());

        $stream = $conversation->submitMessage(new UserMessage('Question'));
        self::assertTrue($signal->isRequested());
        $stream->rewind();
        self::assertFalse($signal->isRequested());
        self::assertFalse($conversation->responseStopRequested());
        iterator_to_array($stream);
        self::assertFalse((new Conversation($this->agent('No stop'), new SessionStore(new InMemoryStorage(), 'local')))->requestInterruption());
    }

    public function testForeignInitialSessionIsRejected(): void
    {
        $storage = new InMemoryStorage();
        $foreign = (new SessionStore($storage, 'other'))->create();
        $this->expectException(InvalidArgumentException::class);
        new Conversation($this->agent('Answer'), new SessionStore($storage, 'owner'), session: $foreign);
    }

    public function testSessionSelectionsAreValidatedThroughOwnerStore(): void
    {
        $storage = new InMemoryStorage();
        $foreign = (new SessionStore($storage, 'other'))->create();
        $conversation = new Conversation($this->agent('Answer'), new SessionStore($storage, 'owner'));
        $this->expectException(InvalidArgumentException::class);
        $conversation->useSession($foreign);
    }

    public function testExistingMessagesRequireExplicitSession(): void
    {
        $agent = $this->agent('Answer')->setThreadId('existing');
        $agent->getChatHistory()->addMessage(new UserMessage('Saved'));
        $this->expectException(InvalidArgumentException::class);
        new Conversation($agent, new SessionStore(new InMemoryStorage(), 'local'));
    }

    private function agent(string ...$answers): Agent
    {
        return (new Agent())->setAiProvider(new FakeAIProvider(...array_map(static fn(string $answer) => new AssistantMessage($answer), $answers)));
    }
}
