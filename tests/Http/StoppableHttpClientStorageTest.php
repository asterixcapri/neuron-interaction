<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Http;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronInteraction\Http\StoppableHttpClient;
use NeuronInteraction\Http\StopSignal;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Storage\StoredDocument;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class StoppableHttpClientStorageTest extends TestCase
{
    public function testAnotherProcessCanStopStreamingAndNeuronSavesThePartialHistory(): void
    {
        $directory = sys_get_temp_dir() . '/neuron-stop-' . bin2hex(random_bytes(8));
        $storage = new FileStorage($directory);
        $key = 'chat-42';
        $stopSignal = new StopSignal($storage, $key);
        $stopSignal->clear();
        $stream = new FixtureStream(StoppableHttpClientTest::openaiText());
        $agent = new Agent();
        $agent->setAiProvider(new OpenAI('fixture-key', 'fixture-model', httpClient: new StoppableHttpClient(new FixtureHttpClient([$stream]), $stopSignal, pollInterval: 0)));

        try {
            $handler = $agent->stream(new UserMessage('Question'));
            $text = '';
            foreach ($handler->events() as $event) {
                if ($event instanceof TextChunk) {
                    $text .= $event->content;
                    $this->requestFromAnotherProcess($directory, $key);
                }
            }

            self::assertSame('Partial', $text);
            self::assertSame('Partial', $handler->getMessage()->getContent());
            self::assertCount(2, $agent->getChatHistory()->getMessages());
            self::assertSame(1, $stream->closes);
            self::assertFalse((new StopSignal(new FileStorage($directory), $key))->isRequested());
        } finally {
            $stopSignal->clear();
            rmdir($directory . '/response-stops');
            rmdir($directory);
        }
    }

    public function testKeysIsolateChatsAndAConsumedFlagDoesNotStopTheNextStream(): void
    {
        $storage = new InMemoryStorage();
        $firstSignal = new StopSignal($storage, 'first');
        $secondSignal = new StopSignal($storage, 'second');
        $first = $this->stream($firstSignal);
        $second = $this->stream($secondSignal);
        $firstSignal->request();

        self::assertTrue($first->eof());
        self::assertSame('', $first->read(1));
        self::assertSame('', $first->readLine());
        self::assertFalse($second->eof());
        self::assertFalse($firstSignal->isRequested());
        self::assertFalse($this->stream($firstSignal)->eof());

        $firstSignal->request();
        self::assertTrue($first->eof());
        self::assertTrue($firstSignal->isRequested());
    }

    public function testProviderPollingDoesNotReadStorageForEveryByte(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::never())->method('create');
        $storage->expects(self::never())->method('write');
        $storage->expects(self::once())->method('read')->willReturn(null);
        $storage->expects(self::never())->method('delete');
        $polls = 0;
        $client = new StoppableHttpClient(
            new FixtureHttpClient([new FixtureStream(str_repeat('x', 1000))]),
            new StopSignal($storage, 'chat'),
            onPoll: static function () use (&$polls): void { ++$polls; },
            pollInterval: 3600,
        );
        $stream = $client->stream(HttpRequest::post('response'));
        $text = '';
        while (!$stream->eof()) {
            $text .= $stream->read(1);
        }

        self::assertSame(str_repeat('x', 1000), $text);
        self::assertSame(1, $polls);
    }

    public function testASignalWrittenAfterTheFirstPollIsObservedOnALaterPoll(): void
    {
        $storage = new InMemoryStorage();
        $stopSignal = new StopSignal($storage, 'chat');
        $client = new StoppableHttpClient(new FixtureHttpClient([new FixtureStream('response')]), $stopSignal);
        $stream = $client->stream(HttpRequest::post('response'));
        self::assertFalse($stream->eof());
        $stopSignal->request();

        usleep(20_000);

        self::assertTrue($stream->eof());
        self::assertFalse($stopSignal->isRequested());
    }

    public function testConfigurationCopiesRetainTheFlagAndTheHostsPollingCallback(): void
    {
        $storage = new InMemoryStorage();
        $stopSignal = new StopSignal($storage, 'chat');
        $inner = new FixtureStream('response');
        $client = new StoppableHttpClient(
            new FixtureHttpClient([$inner]),
            $stopSignal,
            onPoll: static function () use ($stopSignal): void {
                $stopSignal->request();
            },
        );
        $stream = $client->withBaseUri('https://fixture.invalid')->withHeaders(['X-Test' => 'kept'])->withTimeout(12.5)->stream(HttpRequest::post('response'));

        self::assertTrue($stream->eof());
        self::assertTrue($stream->eof());
        self::assertSame(1, $inner->closes);
        self::assertFalse($stopSignal->isRequested());
    }

    public function testStreamingWithoutAStopNeverCreatesStorageFiles(): void
    {
        $directory = sys_get_temp_dir() . '/neuron-no-stop-' . bin2hex(random_bytes(8));
        $storage = new FileStorage($directory);
        $stopSignal = new StopSignal($storage, 'chat');
        $stopSignal->clear();
        $stream = $this->stream($stopSignal);
        self::assertFalse($stream->eof());
        self::assertSame('response', $stream->read(8));
        self::assertTrue($stream->eof());
        self::assertDirectoryDoesNotExist($directory);
    }

    public function testMalformedStoredFlagsAreRejected(): void
    {
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('read')->willReturn(new StoredDocument('chat', ['requested' => 'true'], []));
        $this->expectException(UnexpectedValueException::class);
        $this->stream(new StopSignal($storage, 'chat'))->eof();
    }

    private function stream(StopSignal $stopSignal): StreamInterface
    {
        return (new StoppableHttpClient(new FixtureHttpClient([new FixtureStream('response')]), $stopSignal, pollInterval: 0))->stream(HttpRequest::post('response'));
    }

    private function requestFromAnotherProcess(string $directory, string $key): void
    {
        $script = <<<'WORKER'
require $argv[1];
$storage = new \NeuronInteraction\Storage\FileStorage($argv[2]);
(new \NeuronInteraction\Http\StopSignal($storage, $argv[3]))->request();
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
