<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Subagents;

use Generator;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\SubagentCompleted;
use NeuronInteraction\Exception\ExecutionBusyException;
use NeuronInteraction\Execution\ExecutionLock;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Message\MessageProcessorInterface;
use NeuronInteraction\Message\MessageProcessors;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Session\SessionTitleGenerator;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Subagents\{BackgroundAdapterInterface, Executor, Registry, SubagentStore, Subagents};
use NeuronInteraction\Tests\Tools\CallbackTool;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

use function array_filter;
use function array_values;
use function count;
use function iterator_to_array;
use function json_encode;
use function substr_count;

use const JSON_THROW_ON_ERROR;

final class SubagentsTest extends TestCase
{
    private InMemoryStorage $storage;
    private LockFactory $locks;
    private SubagentStore $runs;
    private SessionStore $sessions;
    private Session $session;
    private Subagents $subagents;
    private Executor $executor;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->locks = new LockFactory(new InMemoryStore());
        $this->runs = new SubagentStore($this->storage, $this->locks);
        $this->sessions = new SessionStore($this->storage, new InMemoryMessageStore(), 'alice');
        $this->session = $this->sessions->create();
        $registry = (new Registry())->register('researcher', 'Research supplied data', static fn(): Agent
            => (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Child result'))));
        $adapter = new class implements BackgroundAdapterInterface {
            public function dispatch(string $runId): void {}
        };
        $this->subagents = new Subagents($registry, $this->runs, $adapter);
        $this->executor = new Executor($registry, $this->runs);
    }

    private function conversation(FakeAIProvider $provider, ?Session $session = null): Conversation
    {
        return (new Conversation((new Agent())->setAiProvider($provider), $session ?? $this->session))
            ->setSubagents($this->subagents);
    }

    public function testToolkitGuidelinesReachEveryTurnWithoutReplacingConfiguredInstructionsOrTools(): void
    {
        $instructions = 'Answer in Italian and follow the user reporting preferences.';
        $provider = new FakeAIProvider(
            new AssistantMessage('Started.'),
            new AssistantMessage('Result received.'),
            new AssistantMessage('Next answer.'),
        );
        $agent = (new Agent())->setAiProvider($provider)->setInstructions($instructions)
            ->addTool(new CallbackTool('existing_tool'));
        $conversation = (new Conversation($agent, $this->session))->setSubagents($this->subagents);
        $run = $this->subagents->spawn($this->session, 'researcher', 'Task');
        $this->executor->execute($run->id);
        iterator_to_array($conversation->sendInput('Hello.'));
        iterator_to_array($conversation->processCompletions());
        iterator_to_array($conversation->sendInput('Continue.'));

        self::assertCount(3, $provider->getRecorded());
        foreach ($provider->getRecorded() as $request) {
            self::assertNotNull($request->systemPrompt);
            $prompt = $request->systemPrompt->getContent();
            self::assertNotNull($prompt);
            self::assertStringContainsString($instructions, $prompt);
            self::assertSame(1, substr_count($prompt, '<TOOLS-GUIDELINES>'));
            $names = [];
            foreach ($request->tools as $tool) {
                $names[] = $tool->getName();
            }
            self::assertEqualsCanonicalizing(
                ['existing_tool', 'spawn_subagent', 'send_subagent_message', 'subagent_status'],
                $names,
            );
        }
        self::assertSame($instructions, $agent->getInstructions()->getContent());
        self::assertSame($instructions, $conversation->agent()->getInstructions()->getContent());
        self::assertCount(1, $conversation->agent()->getTools());
    }

    /** @return iterable<string, array{bool}> */
    public static function resultReadingTurns(): iterable
    {
        yield 'human turn' => [false];
        yield 'automatic turn' => [true];
    }

    #[DataProvider('resultReadingTurns')]
    public function testResultsReadInACompletedTurnAreNotNotifiedAgain(bool $automatic): void
    {
        $runs = [];
        $calls = [];
        for ($index = 0; $index < 5; ++$index) {
            $run = $this->subagents->spawn($this->session, 'researcher', 'Task ' . $index);
            $this->executor->execute($run->id);
            $runs[] = $run;
            $calls[] = new ToolCall('subagent_status', 'read-' . $index, ['run_id' => $run->id]);
        }
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: $calls),
            new AssistantMessage('Single report based on all five results.'),
            new AssistantMessage('Unwanted duplicate report.'),
        );
        $conversation = $this->conversation($provider);
        iterator_to_array($automatic ? $conversation->processCompletions() : $conversation->sendInput('Give me one report.'));
        self::assertSame([], iterator_to_array($conversation->processCompletions()));
        self::assertCount(2, $provider->getRecorded());
        foreach ($runs as $run) {
            self::assertTrue($this->runs->get($run->id)->acknowledged);
        }
    }

    public function testReadingRunningStatusDoesNotConsumeAFutureResult(): void
    {
        $run = $this->subagents->spawn($this->session, 'researcher', 'Task');
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [new ToolCall('subagent_status', 'read', ['run_id' => $run->id])]),
            new AssistantMessage('Still running.'),
            new AssistantMessage('Now the result is ready.'),
        );
        $conversation = $this->conversation($provider);
        iterator_to_array($conversation->sendInput('Check progress.'));
        $this->executor->execute($run->id);
        // Application reads are observational; only a saved parent turn consumes a result.
        $this->subagents->status($this->session, $run->id);
        $events = iterator_to_array($conversation->processCompletions());
        self::assertNotEmpty($events);
        self::assertCount(3, $provider->getRecorded());
        self::assertTrue($this->runs->get($run->id)->acknowledged);
    }

    public function testFailedTurnDoesNotConsumeAResultItRead(): void
    {
        $run = $this->subagents->spawn($this->session, 'researcher', 'Task');
        $this->executor->execute($run->id);
        $read = new ToolCallMessage(tools: [new ToolCall('subagent_status', 'read', ['run_id' => $run->id])]);
        // The first result is persisted by the second inference; the third fails.
        $provider = new FakeAIProvider($read, new ToolCallMessage(tools: [new ToolCall('subagent_status', 'read-again', ['run_id' => $run->id])]));
        try {
            iterator_to_array($this->conversation($provider)->sendInput('Read the result.'));
            self::fail('Expected provider exhaustion');
        } catch (ProviderException) {
            self::assertFalse($this->runs->get($run->id)->acknowledged);
        }
        $recovery = new FakeAIProvider(new AssistantMessage('Recovered result.'));
        iterator_to_array($this->conversation($recovery)->processCompletions());
        self::assertCount(1, $recovery->getRecorded());
        self::assertTrue($this->runs->get($run->id)->acknowledged);
    }

    public function testParentCanConsumeFourResultsSilentlyAndReportAfterTheFifth(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('I will gather all five results.'),
            new AssistantMessage(),
            new AssistantMessage(),
            new AssistantMessage(),
            new AssistantMessage(),
            new AssistantMessage('One report covering all five results.'),
        );
        $conversation = $this->conversation($provider);
        iterator_to_array($conversation->sendInput('Run five tasks and give me a single final report.'));
        $runs = [];
        for ($index = 0; $index < 5; ++$index) {
            $runs[] = $this->subagents->spawn($this->session, 'researcher', 'Task ' . $index);
        }
        foreach ($runs as $index => $run) {
            $this->executor->execute($run->id);
            $text = '';
            $completed = [];
            foreach ($conversation->processCompletions() as $event) {
                if ($event instanceof TextChunk) {
                    $text .= $event->content;
                } elseif ($event instanceof SubagentCompleted) {
                    $completed[] = $event;
                }
            }
            self::assertSame($index === 4 ? 'One report covering all five results.' : '', $text);
            self::assertCount(1, $completed);
            self::assertSame($index === 4 ? $text : null, $completed[0]->reply->getContent());
            self::assertTrue($this->runs->get($run->id)->acknowledged);
            self::assertSame([], iterator_to_array($conversation->processCompletions()));
            self::assertCount($index + 2, $provider->getRecorded());
        }
        self::assertCount(12, $this->session->getMessages());
    }

    public function testToolDelegationReturnsToTheOriginalConversationAndIsAcknowledgedOnce(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [new ToolCall('spawn_subagent', 'delegate', ['agent' => 'researcher', 'task' => 'Compare A and B'])]),
            new AssistantMessage('I delegated the comparison.'),
            new AssistantMessage('Here is the comparison.'),
        );
        $conversation = $this->conversation($provider);
        $history = new InputHistory($this->storage, 'alice');
        $conversation->setInputHistory($history);
        iterator_to_array($conversation->sendInput('Please delegate a comparison.'));
        $entries = $this->storage->list('subagent-runs');
        self::assertCount(1, $entries);
        $run = $this->executor->execute($entries[0]['key']);
        self::assertSame('Child result', $run->result);
        $events = iterator_to_array($conversation->processCompletions());
        $finished = array_values(array_filter($events, static fn(object $event): bool => $event instanceof SubagentCompleted));
        self::assertCount(1, $finished);
        self::assertInstanceOf(SubagentCompleted::class, $finished[0]);
        self::assertFalse($finished[0]->recovered);
        self::assertSame('Here is the comparison.', $finished[0]->reply->getContent());
        self::assertTrue($this->runs->get($run->id)->acknowledged);
        self::assertSame([], iterator_to_array($conversation->processCompletions()));
        self::assertCount(1, $history->list());
        self::assertCount(3, $provider->getRecorded());
        self::assertStringContainsString('Child result', json_encode($provider->getRecorded()[2]->messages, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('Please delegate a comparison.', json_encode($provider->getRecorded()[2]->messages, JSON_THROW_ON_ERROR));
    }

    public function testNoWorkAndDifferentSessionNeverInvokeTheModel(): void
    {
        $run = $this->subagents->spawn($this->session, 'researcher', 'Task');
        $this->executor->execute($run->id);
        $other = $this->sessions->create();
        $provider = new FakeAIProvider(new AssistantMessage('Original session result'));
        $conversation = $this->conversation($provider, $other);
        self::assertSame([], iterator_to_array($conversation->processCompletions()));
        self::assertSame([], $provider->getRecorded());
        $conversation->useSession($this->session);
        iterator_to_array($conversation->processCompletions());
        self::assertSame([], $other->getMessages());
        self::assertCount(2, $this->session->getMessages());
    }

    public function testToolsBindToTheSessionSelectedForEachTurn(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [new ToolCall('spawn_subagent', 'first', ['agent' => 'researcher', 'task' => 'First'])]),
            new AssistantMessage('Started first'),
            new ToolCallMessage(tools: [new ToolCall('spawn_subagent', 'second', ['agent' => 'researcher', 'task' => 'Second'])]),
            new AssistantMessage('Started second'),
        );
        $conversation = $this->conversation($provider);
        iterator_to_array($conversation->sendInput('First'));
        $second = $this->sessions->create();
        $conversation->useSession($second);
        iterator_to_array($conversation->sendInput('Second'));
        $runs = $this->storage->list('subagent-runs');
        self::assertCount(2, $runs);
        self::assertSame($this->session->getKey(), $this->runs->get($runs[0]['key'])->sessionKey);
        self::assertSame($second->getKey(), $this->runs->get($runs[1]['key'])->sessionKey);
    }

    public function testCompletionBypassesHumanMessageProcessors(): void
    {
        $run = $this->subagents->spawn($this->session, 'researcher', 'Task');
        $this->executor->execute($run->id);
        $conversation = $this->conversation(new FakeAIProvider(new AssistantMessage('Done')));
        $processor = new class implements MessageProcessorInterface {
            public function forAgent(UserMessage $message): UserMessage
            {
                throw new LogicException('Human processor must not run');
            }
            public function forDisplay(UserMessage $message): UserMessage
            {
                throw new LogicException('Human display processor must not run');
            }
        };
        $conversation->setMessageProcessors(new MessageProcessors($processor));
        iterator_to_array($conversation->processCompletions());
        self::assertCount(2, $conversation->getDisplayMessages());
        self::assertTrue($this->runs->get($run->id)->acknowledged);
    }

    public function testHumanAndAutomaticTurnsShareTheLockUntilStreamIsReleased(): void
    {
        $run = $this->subagents->spawn($this->session, 'researcher', 'Task');
        $this->executor->execute($run->id);
        $human = $this->conversation(new FakeAIProvider(new AssistantMessage('Human response')));
        $automaticProvider = new FakeAIProvider(new AssistantMessage('Automatic response'));
        $automatic = $this->conversation($automaticProvider);
        $stream = $human->sendInput('Hello');
        $stream->rewind();
        self::assertSame([], iterator_to_array($automatic->processCompletions()));
        self::assertSame([], $automaticProvider->getRecorded());
        try {
            iterator_to_array($automatic->sendInput('Another input'));
            self::fail('Expected contention');
        } catch (ExecutionBusyException) {
            self::assertFalse($this->runs->get($run->id)->acknowledged);
        }
        iterator_to_array($stream);
        iterator_to_array($automatic->processCompletions());
        self::assertTrue($this->runs->get($run->id)->acknowledged);
        self::assertCount(1, $automaticProvider->getRecorded());
    }

    public function testAbandoningAStreamReleasesTheSessionLock(): void
    {
        $one = $this->conversation(new FakeAIProvider(new AssistantMessage('Partial answer')));
        $stream = $one->sendInput('Question');
        $stream->rewind();
        unset($stream);
        $two = $this->conversation(new FakeAIProvider(new AssistantMessage('Next answer')));
        iterator_to_array($two->sendInput('Next'));
        self::assertTrue($this->session->hasContent());
    }

    public function testAnotherUserCannotReadOrCorrectARun(): void
    {
        $run = $this->subagents->spawn($this->session, 'researcher', 'Task');
        $bob = (new SessionStore($this->storage, new InMemoryMessageStore(), 'bob'))->create();
        foreach ([fn() => $this->subagents->status($bob, $run->id), fn() => $this->subagents->sendMessage($bob, $run->id, 'Intrusion')] as $operation) {
            try {
                $operation();
                self::fail('Expected ownership check');
            } catch (OutOfBoundsException) {
                self::assertSame([], $this->runs->get($run->id)->messages);
            }
        }
    }

    public function testLateCorrectionIsConsumedBeforeTheChildCanComplete(): void
    {
        $run = $this->subagents->spawn($this->session, 'researcher', 'Original task');
        $provider = new class ($this->subagents, $this->session, $run->id) extends FakeAIProvider {
            public function __construct(private Subagents $subagents, private Session $session, private string $id)
            {
                parent::__construct(new AssistantMessage('Old'), new AssistantMessage('Corrected'));
            }
            public function chat(Message ...$messages): ProviderResponse
            {
                $response = parent::chat(...$messages);
                if (count($this->getRecorded()) === 1) {
                    $this->subagents->sendMessage($this->session, $this->id, 'Include the new requirement');
                }
                return $response;
            }
        };
        $registry = (new Registry())->register('researcher', 'Research', static fn(): Agent => (new Agent())->setAiProvider($provider));
        $executor = new Executor($registry, $this->runs);
        self::assertSame('Corrected', $executor->execute($run->id)->result);
        self::assertCount(2, $provider->getRecorded());
        self::assertStringContainsString('Include the new requirement', json_encode($provider->getRecorded()[1]->messages, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->session->getMessages());
        self::assertSame('Corrected', $executor->execute($run->id)->result);
        self::assertCount(2, $provider->getRecorded());
    }

    public function testASecondExecutorCannotRunTheSameTaskConcurrently(): void
    {
        $run = $this->subagents->spawn($this->session, 'researcher', 'Task');
        $lock = ExecutionLock::acquire($this->locks, 'run.' . $run->id);
        try {
            $this->expectException(ExecutionBusyException::class);
            $this->executor->execute($run->id);
        } finally {
            $lock->release();
        }
    }

    public function testARecoveryNoteIsNotMistakenForACompletedParentResponse(): void
    {
        $run = $this->subagents->spawn($this->session, 'researcher', 'Task');
        $this->executor->execute($run->id);
        $tool = (new CallbackTool('lookup'))->setCallable(static fn(): string => 'Tool result');
        $provider = new class extends FakeAIProvider {
            public function __construct()
            {
                parent::__construct(new ToolCallMessage(tools: [new ToolCall('lookup', 'call')]), new AssistantMessage('Fail'));
            }
            protected function streamChunks(Message $response): Generator
            {
                if ($response->getContent() === 'Fail') {
                    throw new RuntimeException('Provider failed');
                }
                return yield from parent::streamChunks($response);
            }
        };
        $conversation = (new Conversation((new Agent())->setAiProvider($provider)->addTool($tool), $this->session))->setSubagents($this->subagents);
        try {
            iterator_to_array($conversation->processCompletions());
            self::fail('Expected provider failure');
        } catch (RuntimeException $error) {
            self::assertSame('Provider failed', $error->getMessage());
        }
        self::assertFalse($this->runs->get($run->id)->acknowledged);
        $retryProvider = new FakeAIProvider(new AssistantMessage('Recovered outcome'));
        iterator_to_array($this->conversation($retryProvider)->processCompletions());
        self::assertCount(1, $retryProvider->getRecorded());
        self::assertTrue($this->runs->get($run->id)->acknowledged);
    }
    public function testApplicationEventsDoNotGenerateOrContaminateSessionTitles(): void
    {
        $run = $this->subagents->spawn($this->session, 'researcher', 'INTERNAL_ONLY_TASK');
        $this->executor->execute($run->id);
        $titles = new FakeAIProvider(new AssistantMessage('{"title":"User topic"}'));
        $conversation = $this->conversation(new FakeAIProvider(new AssistantMessage('Outcome'), new AssistantMessage('Human answer')));
        $conversation->setSessionTitleGenerator(new SessionTitleGenerator($titles));
        iterator_to_array($conversation->processCompletions());
        $beforeHumanInput = $titles->getRecorded();
        self::assertSame([], $beforeHumanInput);
        iterator_to_array($conversation->sendInput('USER_TOPIC'));
        self::assertCount(1, $titles->getRecorded());
        $prompt = json_encode($titles->getRecorded()[0]->messages, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('USER_TOPIC', $prompt);
        self::assertStringNotContainsString('INTERNAL_ONLY_TASK', $prompt);
    }

    public function testStopRequestCanBeWrittenWhileTheHumanTurnOwnsTheLock(): void
    {
        $signal = new StopSignal($this->storage, 'human-execution');
        $conversation = $this->conversation(new FakeAIProvider(new AssistantMessage('Long response')))->setStopSignal($signal);
        $stream = $conversation->sendInput('Question');
        $stream->rewind();
        self::assertTrue($conversation->requestInterruption());
        self::assertTrue($signal->isRequested());
        unset($stream);
    }

}
