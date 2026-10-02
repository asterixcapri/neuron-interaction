<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Interruption;

use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Storage\StoredDocument;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use function bin2hex;
use function dirname;
use function fclose;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function usleep;

use const PHP_BINARY;

final class StopSignalStorageTest extends TestCase
{
    public function testACallbackConsumesARequestFromAnotherProcess(): void
    {
        $directory = sys_get_temp_dir() . '/neuron-stop-' . bin2hex(random_bytes(8));
        $signal = new StopSignal(new FileStorage($directory), 'chat-42');
        $callback = $signal->stopCallback(pollInterval: 0);
        self::assertFalse($callback());
        try {
            $this->requestFromAnotherProcess($directory, 'chat-42');
            self::assertTrue($callback());
            self::assertFalse((new StopSignal(new FileStorage($directory), 'chat-42'))->isRequested());
            self::assertFalse($callback());
        } finally {
            $signal->clear();
            rmdir($directory . '/response-stops');
            rmdir($directory);
        }
    }

    public function testKeysIsolateStopRequests(): void
    {
        $storage = new InMemoryStorage();
        $first = new StopSignal($storage, 'first');
        $second = new StopSignal($storage, 'second');
        $first->request();
        self::assertFalse($second->stopCallback(pollInterval: 0)());
        self::assertTrue($first->isRequested());
        self::assertTrue($first->stopCallback(pollInterval: 0)());
        self::assertFalse($first->isRequested());
    }

    public function testPollingIsThrottledWithoutWritingStorage(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::never())->method('create');
        $storage->expects(self::never())->method('write');
        $storage->expects(self::once())->method('read')->willReturn(null);
        $storage->expects(self::never())->method('delete');
        $polls = 0;
        $callback = (new StopSignal($storage, 'chat'))->stopCallback(
            onPoll: static function () use (&$polls): void { ++$polls; },
            pollInterval: 3600,
        );
        for ($poll = 0; $poll < 1000; ++$poll) {
            self::assertFalse($callback());
        }
        self::assertSame(1, $polls);
    }

    public function testARequestAfterTheFirstPollIsObservedOnALaterPoll(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'chat');
        $callback = $signal->stopCallback();
        self::assertFalse($callback());
        $signal->request();
        usleep(20_000);
        self::assertTrue($callback());
        self::assertFalse($signal->isRequested());
    }

    public function testTheHostCanRequestAStopDuringPolling(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'chat');
        $callback = $signal->stopCallback(onPoll: static fn() => $signal->request());
        self::assertTrue($callback());
        self::assertFalse($signal->isRequested());
    }

    public function testPollingWithoutARequestCreatesNoFiles(): void
    {
        $directory = sys_get_temp_dir() . '/neuron-no-stop-' . bin2hex(random_bytes(8));
        $signal = new StopSignal(new FileStorage($directory), 'chat');
        self::assertFalse($signal->stopCallback()());
        self::assertDirectoryDoesNotExist($directory);
    }

    public function testMalformedStoredFlagsAreRejected(): void
    {
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('read')->willReturn(new StoredDocument('chat', ['requested' => 'true'], []));
        $this->expectException(UnexpectedValueException::class);
        (new StopSignal($storage, 'chat'))->stopCallback()();
    }

    private function requestFromAnotherProcess(string $directory, string $key): void
    {
        $script = <<<'WORKER'
            use NeuronInteraction\Interruption\StopSignal;
            use NeuronInteraction\Storage\FileStorage;

            require $argv[1];
            $storage = new FileStorage($argv[2]);
            (new StopSignal($storage, $argv[3]))->request();
            WORKER;
        $process = proc_open(
            [PHP_BINARY, '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $directory, $key],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
    }
}
