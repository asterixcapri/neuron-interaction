<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Subagents;

use LogicException;
use NeuronAI\Agent\Adapters\AgentChunkAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\SubagentCompleted;
use NeuronInteraction\Event\SubagentDispatched;
use NeuronInteraction\Execution\ExecutionLock;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Subagents\Adapter\{ConsoleAdapter, ExecuteSubagent, ExecuteSubagentHandler, MessengerAdapter, MessengerCompletionRelay, ProcessCompletions, ProcessCompletionsHandler};
use NeuronInteraction\Subagents\{BackgroundAdapterInterface, Executor, Registry, SubagentStore, Subagents};
use NeuronInteraction\Tests\EventCollector;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\PdoStore;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Process\Process;

use function bin2hex;
use function dirname;
use function extension_loaded;
use function file_get_contents;
use function microtime;
use function putenv;
use function random_bytes;
use function sys_get_temp_dir;
use function usleep;

use const PHP_BINARY;

final class IntegrationTest extends TestCase
{
    private string $directory;
    protected function setUp(): void
    {
        if (!extension_loaded('sysvsem')) {
            self::markTestSkipped('SemaphoreStore requires ext-sysvsem.');
        }
        $this->directory = sys_get_temp_dir() . '/interaction-subagents-' . bin2hex(random_bytes(8));
    }
    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
    }
    private function locks(): LockFactory
    {
        return new LockFactory(new SemaphoreStore());
    }
    private function registry(): Registry
    {
        return (new Registry())->register('researcher', 'Research', static fn(): Agent
            => (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Child result'))));
    }
    private function noDispatch(): BackgroundAdapterInterface
    {
        return new class implements BackgroundAdapterInterface {
            public function dispatch(string $runId): void {}
        };
    }

    /** @return iterable<string, array{?string}> */
    public static function parentAnswers(): iterable
    {
        yield 'text response' => ['Saved parent answer'];
        yield 'silent completion' => [null];
    }

    #[DataProvider('parentAnswers')]
    public function testRestartAfterSavedAnswerDoesNotGenerateOrStreamADuplicate(?string $answer): void
    {
        $storage = new FileStorage($this->directory);
        $faults = new FailingStorage($storage);
        $messages = new FileMessageStore($this->directory . '/messages');
        $session = (new SessionStore($storage, $messages, 'alice'))->create();
        $runs = new SubagentStore($faults, $this->locks());
        $module = new Subagents($this->registry(), $runs, $this->noDispatch());
        $run = $module->spawn($session, 'researcher', 'Task');
        (new Executor($this->registry(), $runs))->execute($run->id);
        $provider = new FakeAIProvider(new AssistantMessage($answer));
        $conversation = (new Conversation((new Agent())->setAiProvider($provider), $session))->setSubagents($module);
        try {
            EventCollector::completions($conversation);
            self::fail('Expected acknowledgement failure');
        } catch (RuntimeException $error) {
            self::assertSame('Crash before acknowledgement', $error->getMessage());
        }
        self::assertCount(2, $session->getMessages());
        self::assertFalse($runs->get($run->id)->acknowledged);

        $reopened = new FileStorage($this->directory);
        $restored = (new SessionStore($reopened, new FileMessageStore($this->directory . '/messages'), 'alice'))->get($session->getKey());
        self::assertNotNull($restored);
        $newRuns = new SubagentStore($reopened, $this->locks());
        $noResponses = new FakeAIProvider();
        $events = [];
        $retry = (new Conversation((new Agent())->setAiProvider($noResponses), $restored))
            ->setSubagents(new Subagents($this->registry(), $newRuns, $this->noDispatch(), onEvent: static function (SubagentDispatched|SubagentCompleted $event) use (&$events, $newRuns): void {
                self::assertTrue($newRuns->get($event->run->id)->acknowledged);
                $events[] = $event;
            }));
        self::assertFalse($newRuns->get($run->id)->acknowledged);
        self::assertSame([], EventCollector::completions($retry));
        self::assertCount(1, $events);
        self::assertInstanceOf(SubagentCompleted::class, $events[0]);
        self::assertTrue($events[0]->recovered);
        self::assertSame($answer, $events[0]->reply->getContent());
        self::assertSame([], $noResponses->getRecorded());
        self::assertTrue($newRuns->get($run->id)->acknowledged);
        self::assertCount(2, $restored->getMessages());
    }

    public function testRestartReconcilesResultsReadBeforeTheAnswerWasSaved(): void
    {
        $storage = new FileStorage($this->directory);
        $messages = new FileMessageStore($this->directory . '/messages');
        $session = (new SessionStore($storage, $messages, 'alice'))->create();
        $runs = new SubagentStore($storage, $this->locks());
        $module = new Subagents($this->registry(), $runs, $this->noDispatch());
        $calls = [];
        for ($index = 0; $index < 2; ++$index) {
            $run = $module->spawn($session, 'researcher', 'Task ' . $index);
            (new Executor($this->registry(), $runs))->execute($run->id);
            $calls[] = new ToolCall('subagent_status', 'read-' . $index, ['run_id' => $run->id]);
        }
        $provider = new FakeAIProvider(new ToolCallMessage(tools: $calls), new AssistantMessage('Combined report.'));
        $conversation = (new Conversation((new Agent())->setAiProvider($provider), $session))->setSubagents($module);
        EventCollector::input($conversation, 'Read the results and summarize.');
        self::assertCount(2, $runs->pending($session));

        // Reopen after the answer write, before any completion poll acknowledges it.
        $reopened = new FailingStorage(new FileStorage($this->directory));
        $restored = (new SessionStore($reopened, new FileMessageStore($this->directory . '/messages'), 'alice'))->get($session->getKey());
        self::assertNotNull($restored);
        $newRuns = new SubagentStore($reopened, $this->locks());
        $noResponses = new FakeAIProvider();
        $retry = (new Conversation((new Agent())->setAiProvider($noResponses), $restored))
            ->setSubagents(new Subagents($this->registry(), $newRuns, $this->noDispatch()));
        try {
            EventCollector::completions($retry);
            self::fail('Expected acknowledgement failure');
        } catch (RuntimeException $error) {
            self::assertSame('Crash before acknowledgement', $error->getMessage());
        }
        self::assertSame([], EventCollector::completions($retry));
        self::assertSame([], $noResponses->getRecorded());
        self::assertSame([], $newRuns->pending($restored));
    }

    public function testConsoleAdapterRunsTheChildInASeparateProcess(): void
    {
        $storage = new FileStorage($this->directory);
        $session = (new SessionStore($storage, new FileMessageStore($this->directory . '/messages'), 'alice'))->create();
        $runs = new SubagentStore($storage, $this->locks());
        $adapter = new ConsoleAdapter([PHP_BINARY, __DIR__ . '/worker.php'], $this->directory . '/worker.log');
        putenv('NEURON_SUBAGENT_TEST_STORAGE=' . $this->directory);
        try {
            $run = (new Subagents($this->registry(), $runs, $adapter))->spawn($session, 'researcher', 'Task');
            $deadline = microtime(true) + 8;
            do {
                usleep(20_000);
                $finished = $runs->get($run->id);
            } while ($finished->status !== 'completed' && $finished->status !== 'failed' && microtime(true) < $deadline);
            self::assertSame('completed', $finished->status, file_get_contents($this->directory . '/worker.log') ?: 'No worker output');
            self::assertSame('From a separate PHP process', $finished->result);
            $provider = new FakeAIProvider(new AssistantMessage('Automatic terminal response'));
            $conversation = (new Conversation((new Agent())->setAiProvider($provider), $session))
                ->setSubagents(new Subagents($this->registry(), $runs, $adapter));
            EventCollector::completions($conversation);
            self::assertTrue($runs->get($run->id)->acknowledged);
            self::assertSame('Automatic terminal response', $session->getMessages()[1]->getContent());
        } finally {
            putenv('NEURON_SUBAGENT_TEST_STORAGE');
        }
    }

    public function testSessionLockCoordinatesIndependentProcessesAndIsReleasedOnExit(): void
    {
        $key = 'test.' . bin2hex(random_bytes(8));
        $lock = ExecutionLock::acquire($this->locks(), $key);
        $code = <<<'CODE'
            require $argv[1];
            try {
                $lock = \NeuronInteraction\Execution\ExecutionLock::acquire(
                    new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\SemaphoreStore()), $argv[2]);
                echo 'acquired';
            } catch (\NeuronInteraction\Exception\ExecutionBusyException) { echo 'busy'; }
            CODE;
        $process = new Process([PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $key]);
        $process->mustRun();
        self::assertSame('busy', $process->getOutput());
        $lock->release();
        $process->mustRun();
        self::assertSame('acquired', $process->getOutput());
        $again = ExecutionLock::acquire($this->locks(), $key);
        $again->release();
    }

    public function testExpiringBackendIsRejectedInsteadOfSilentlyLosingExclusivity(): void
    {
        $factory = new LockFactory(new PdoStore(new PDO('sqlite::memory:')));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('non-expiring');
        ExecutionLock::acquire($factory, 'unsafe-expiry');
    }
    public function testMessengerRoutesChildrenAndDuplicateWakeupsAndPublishesThroughTheAgentChannel(): void
    {
        $storage = new FileStorage($this->directory);
        $messages = new FileMessageStore($this->directory . '/messages');
        $sessions = new SessionStore($storage, $messages, 'alice');
        $session = $sessions->create();
        $runs = new SubagentStore($storage, $this->locks());
        $registry = $this->registry();
        $children = new InMemoryTransport();
        $parents = new InMemoryTransport();
        $senders = new class ($children, $parents) implements SendersLocatorInterface {
            public function __construct(private InMemoryTransport $children, private InMemoryTransport $parents) {}
            public function getSenders(Envelope $envelope): iterable
            {
                if ($envelope->getMessage() instanceof ExecuteSubagent) {
                    yield 'subagents' => $this->children;
                }
                if ($envelope->getMessage() instanceof ProcessCompletions) {
                    yield 'parents' => $this->parents;
                }
            }
        };
        $provider = new FakeAIProvider(new AssistantMessage('Delivered through a channel'));
        $published = [];
        $channel = new CallbackChannel(static function (ProtocolEvent $event) use (&$published): void { $published[] = $event; });
        $parentModule = new Subagents($registry, $runs, $this->noDispatch());
        $parentHandler = new ProcessCompletionsHandler(function (string $user, string $key) use ($storage, $messages, $provider, $channel, $parentModule): Conversation {
            $restored = (new SessionStore($storage, $messages, $user))->get($key);
            if ($restored === null) {
                throw new LogicException('Missing session/module');
            }
            $agent = (new Agent())->setAiProvider($provider)
                ->setStreamAdapter(static fn(): AgentChunkAdapter => new AgentChunkAdapter())
                ->setChannel(static fn(): CallbackChannel => $channel);
            return (new Conversation($agent, $restored))->setSubagents($parentModule);
        });
        $bus = new MessageBus([
            new SendMessageMiddleware($senders),
            new HandleMessageMiddleware(new HandlersLocator([
                ExecuteSubagent::class => [new ExecuteSubagentHandler(new Executor($registry, $runs))],
                ProcessCompletions::class => [$parentHandler],
            ])),
        ]);
        $module = new Subagents($registry, $runs, new MessengerAdapter($bus));
        $run = $module->spawn($session, 'researcher', 'Task');
        self::assertSame('queued', $runs->get($run->id)->status);
        foreach ($children->get() as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('subagents')));
            $children->ack($envelope);
        }
        self::assertSame('completed', $runs->get($run->id)->status);
        $relay = new MessengerCompletionRelay($runs, $bus);
        $relay->dispatchPending();
        $relay->dispatchPending();
        self::assertCount(2, $parents->getSent());
        foreach ($parents->get() as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('parents')));
            $parents->ack($envelope);
        }
        self::assertCount(1, $provider->getRecorded());
        self::assertNotEmpty($published);
        self::assertTrue($runs->get($run->id)->acknowledged);
        self::assertSame(0, $relay->dispatchPending());
    }

    public function testKillingAWorkerReleasesItsSemaphoreLock(): void
    {
        $key = 'crash.' . bin2hex(random_bytes(8));
        $code = <<<'CODE'
            require $argv[1];
            $lock = \NeuronInteraction\Execution\ExecutionLock::acquire(
                new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\SemaphoreStore()), $argv[2]);
            echo 'ready';
            flush();
            sleep(30);
            CODE;
        $process = new Process([PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $key]);
        $process->start();
        try {
            $deadline = microtime(true) + 3;
            while ($process->getOutput() !== 'ready' && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertSame('ready', $process->getOutput(), $process->getErrorOutput());
        } finally {
            $process->stop(0, 9);
        }
        $lock = ExecutionLock::acquire($this->locks(), $key);
        self::assertTrue($lock->isAcquired());
        $lock->release();
    }

}
