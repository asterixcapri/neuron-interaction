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
use NeuronInteraction\Conversation\ConversationRuntime;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function iterator_to_array;

final class ConversationRuntimeTest extends TestCase
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
        $runtime = new ConversationRuntime($agent);
        $stream = $runtime->submitMessage(new UserMessage('Hello'));
        self::assertSame($chunks, iterator_to_array($stream));
        self::assertSame($state, $stream->getReturn());
    }

    public function testSubmissionPreparesButOnlyConsumptionExecutesAndUnstartedStreamDoesNotReserve(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('One'), new AssistantMessage('Two'));
        $runtime = new ConversationRuntime((new Agent())->setAiProvider($provider));
        $stream = $runtime->submitMessage(new UserMessage('First'));
        $before = $provider->getRecorded();
        self::assertSame([], $before);
        $historyBefore = $runtime->agent()->getChatHistory()->getMessages();
        self::assertSame([], $historyBefore);
        unset($stream);
        iterator_to_array($runtime->submitMessage(new UserMessage('Second')));
        self::assertCount(1, $provider->getRecorded());
        self::assertSame('Second', $runtime->agent()->getChatHistory()->getMessages()[0]->getContent());
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
        $runtime = new ConversationRuntime($agent);
        $first = $runtime->submitMessage(new UserMessage('First'));
        $second = $runtime->submitMessage(new UserMessage('Second'));
        $first->rewind();
        $second->rewind();
        $third = $runtime->submitMessage(new UserMessage('Third'));
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
        $runtime = new ConversationRuntime($this->agent('Partial', 'Next'));
        $stream = $runtime->submitMessage(new UserMessage('First'));
        foreach ($stream as $chunk) {
            self::assertInstanceOf(TextChunk::class, $chunk);
            break;
        }
        unset($stream);
        $next = $runtime->submitMessage(new UserMessage('Next'));
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
        $runtime = new ConversationRuntime((new Agent())->setAiProvider($provider));
        $text = '';
        try {
            foreach ($runtime->submitMessage(new UserMessage('Fail')) as $chunk) {
                if ($chunk instanceof TextChunk) {
                    $text .= $chunk->content;
                }
            }
            self::fail('Expected failure');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertSame('Partial answer', $text);
        $next = $runtime->submitMessage(new UserMessage('Continue'));
        iterator_to_array($next);
        self::assertSame('Recovered', $next->getReturn()->getMessage()?->getContent());
        self::assertCount(2, $provider->getRecorded());
    }

    public function testExecutionUsesCurrentAgentAndSessionThenRetainsThemAcrossReplacement(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'owner');
        $original = $store->create();
        $selected = $store->create();
        $runtime = new ConversationRuntime($this->agent('Original response'), $store, session: $original);
        $stream = $runtime->submitMessage(new UserMessage('Question'));
        $runtime->useSession($selected);
        $runtime->useAgent($this->agent('Selected response'));
        $stream->rewind();
        $runtime->useSession($original);
        $runtime->useAgent($this->agent('Replacement'));
        iterator_to_array($stream);
        self::assertSame('Selected response', $stream->getReturn()->getMessage()?->getContent());
        self::assertSame([], $runtime->agent()->getChatHistory()->getMessages());
        $saved = $store->read($selected->getKey());
        self::assertNotNull($saved);
        self::assertCount(2, $saved->getMessages());
        self::assertSame($original->getKey(), $runtime->session()->getKey());
        $runtime->useSession($selected);
        self::assertCount(2, $runtime->agent()->getChatHistory()->getMessages());
    }

    public function testConsumedResponseStopResetsAtNextExecution(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'stop');
        $runtime = new ConversationRuntime($this->agent('Partial', 'Next'), stopSignal: $signal);
        $stream = $runtime->submitMessage(new UserMessage('Stop'));
        $stream->rewind();
        self::assertTrue($runtime->requestInterruption());
        self::assertFalse($runtime->requestInterruption());
        self::assertFalse($runtime->responseWasStopped());
        self::assertTrue(($signal->stopCallback(pollInterval: 0))());
        self::assertTrue($runtime->responseWasStopped());
        iterator_to_array($stream);
        self::assertTrue($runtime->responseStopRequested());
        $next = $runtime->submitMessage(new UserMessage('Next'));
        $next->rewind();
        self::assertFalse($runtime->responseStopRequested());
        self::assertFalse($runtime->responseWasStopped());
        iterator_to_array($next);
    }

    public function testPendingAndStaleStopRequestsAreNotReportedAsConsumed(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'pending');
        $signal->request();
        $runtime = new ConversationRuntime($this->agent('Completed', 'Next'), stopSignal: $signal);
        $stream = $runtime->submitMessage(new UserMessage('Question'));
        $stream->rewind();
        self::assertFalse($signal->isRequested());
        self::assertTrue($runtime->requestInterruption());
        iterator_to_array($stream);
        self::assertTrue($runtime->responseStopRequested());
        self::assertFalse($runtime->responseWasStopped());
        iterator_to_array($runtime->submitMessage(new UserMessage('Next')));
        self::assertFalse($signal->isRequested());
        self::assertFalse($runtime->responseStopRequested());
    }

    public function testStopCanBeRequestedWithoutALocalExecutionAndIsClearedAtStreamStart(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'idle-stop');
        $runtime = new ConversationRuntime($this->agent('Answer'), stopSignal: $signal);
        self::assertTrue($runtime->requestInterruption());
        self::assertTrue($signal->isRequested());
        self::assertTrue($runtime->responseStopRequested());
        self::assertFalse($runtime->requestInterruption());

        $stream = $runtime->submitMessage(new UserMessage('Question'));
        self::assertTrue($signal->isRequested());
        $stream->rewind();
        self::assertFalse($signal->isRequested());
        self::assertFalse($runtime->responseStopRequested());
        iterator_to_array($stream);
        self::assertFalse((new ConversationRuntime($this->agent('No stop')))->requestInterruption());
    }

    public function testForeignInitialSessionIsRejected(): void
    {
        $storage = new InMemoryStorage();
        $foreign = (new SessionStore($storage, 'other'))->create();
        $this->expectException(InvalidArgumentException::class);
        new ConversationRuntime($this->agent('Answer'), new SessionStore($storage, 'owner'), session: $foreign);
    }

    public function testSessionSelectionsAreValidatedThroughOwnerStore(): void
    {
        $storage = new InMemoryStorage();
        $foreign = (new SessionStore($storage, 'other'))->create();
        $runtime = new ConversationRuntime($this->agent('Answer'), new SessionStore($storage, 'owner'));
        $this->expectException(InvalidArgumentException::class);
        $runtime->useSession($foreign);
    }

    public function testExistingMessagesRequireExplicitSession(): void
    {
        $agent = $this->agent('Answer')->setThreadId('existing');
        $agent->getChatHistory()->addMessage(new UserMessage('Saved'));
        $this->expectException(InvalidArgumentException::class);
        new ConversationRuntime($agent);
    }

    private function agent(string ...$answers): Agent
    {
        return (new Agent())->setAiProvider(new FakeAIProvider(...array_map(static fn(string $answer) => new AssistantMessage($answer), $answers)));
    }
}
