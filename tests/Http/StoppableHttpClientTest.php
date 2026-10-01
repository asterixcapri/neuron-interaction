<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Http;

use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\Tools\CallbackTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function implode;
use function random_bytes;
use function sys_get_temp_dir;

final class StoppableHttpClientTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function textProviders(): iterable
    {
        yield 'OpenAI Chat Completions' => ['openai', self::openaiText()];
        yield 'OpenAI Responses' => ['responses', implode("\n\n", [
            'data: {"type":"response.output_item.added","item":{"id":"msg-1","type":"message","content":[]}}',
            'data: {"type":"response.output_text.delta","item_id":"msg-1","delta":"Partial"}',
            'data: {"type":"response.output_text.delta","item_id":"msg-1","delta":" hidden"}',
            'data: {"type":"response.completed","response":{"id":"response-1","status":"completed","output":[{"type":"message","role":"assistant","content":[{"type":"output_text","text":"Partial hidden"}]}]}}',
        ]) . "\n\n"];
        yield 'Anthropic' => ['anthropic', implode("\n\n", [
            'data: {"type":"message_start","message":{"id":"msg-1","usage":{"input_tokens":10,"output_tokens":0}}}',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Partial"}}',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" hidden"}}',
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}',
        ]) . "\n\n"];
        yield 'Gemini' => ['gemini', 'data: {"candidates":[{"content":{"parts":[{"text":"Partial"}]}}]}' . "\n\n" . 'data: {"candidates":[{"content":{"parts":[{"text":" hidden"}]},"finishReason":"STOP"}]}' . "\n\n"];
    }

    #[DataProvider('textProviders')]
    public function testNeuronPersistsThePartialAndAgentStepsWithoutClientHistoryWrites(string $name, string $body): void
    {
        $stopSignal = new StopSignal(new InMemoryStorage(), 'conversation');
        $stream = new FixtureStream($body);
        $client = new FixtureHttpClient([$stream, new FixtureStream($body)]);
        $http = new StoppableHttpClient($client, $stopSignal->stopCallback(pollInterval: 0));
        $provider = match ($name) {
            'openai' => new OpenAI('fixture-key', 'fixture-model', httpClient: $http),
            'responses' => new OpenAIResponses('fixture-key', 'fixture-model', httpClient: $http),
            'anthropic' => new Anthropic('fixture-key', 'fixture-model', httpClient: $http),
            'gemini' => new Gemini('fixture-key', 'fixture-model', httpClient: $http),
            default => throw new LogicException('Unknown fixture provider'),
        };
        $agent = $this->agent($provider);
        $key = 'http-stop-' . bin2hex(random_bytes(8));
        $history = new ChatHistory(new FileMessageStore(sys_get_temp_dir()), $key);
        $agent = $agent->for($key)->setMessageStore(new FileMessageStore(sys_get_temp_dir()));

        try {
            $stopSignal->clear();
            $handler = $agent->stream(new UserMessage('Question'));
            foreach ($handler as $chunk) {
                if ($chunk instanceof TextChunk) {
                    $stopSignal->request();
                }
            }

            self::assertFalse($stopSignal->isRequested());
            self::assertSame(1, $stream->closes);
            self::assertSame('Partial', $handler->getReturn()->getMessage()?->getContent());
            self::assertSame('stopped', $handler->getReturn()->getMessage()->getMetadata('stop_reason'));
            self::assertSame($history->getThreadId(), $agent->getChatHistory()->getThreadId());
            self::assertSame(['Question', 'Partial'], array_map(static fn(Message $message): ?string => $message->getContent(), $agent->getChatHistory()->getMessages()));
            self::assertCount(2, $handler->getReturn()->getSteps());

            $reloaded = new ChatHistory(new FileMessageStore(sys_get_temp_dir()), $key);
            self::assertSame('Partial', $agent->getChatHistory()->getLastMessage()->getContent());
            $agent = $agent->for($key)->setMessageStore(new FileMessageStore(sys_get_temp_dir()));
            $stopSignal->clear();
            foreach ($agent->stream(new UserMessage('Continue')) as $chunk) {
            }
            self::assertFalse($stopSignal->isRequested());
            self::assertCount(4, $agent->getChatHistory()->getMessages());
            self::assertSame('Partial hidden', $agent->getChatHistory()->getLastMessage()->getContent());
            self::assertCount(2, $client->requests);
        } finally {
            $stopSignal->clear();
            $history->flushAll();
        }
    }

    public function testStoppingBeforeTextUsesNeuronsEmptyAssistantAndDoesNotInventAMarker(): void
    {
        $stopSignal = new StopSignal(new InMemoryStorage(), 'conversation');
        $client = new FixtureHttpClient([new FixtureStream(self::openaiText())]);
        $agent = $this->agent(new OpenAI('fixture-key', 'fixture-model', httpClient: new StoppableHttpClient($client, $stopSignal->stopCallback(pollInterval: 0))));
        $stopSignal->clear();
        $stopSignal->request();

        try {
            foreach ($agent->stream(new UserMessage('Question')) as $chunk) {
                self::fail('No text should be emitted before the requested EOF.');
            }
            self::fail('Neuron must reject a stop before any answer text.');
        } catch (ProviderException $exception) {
            self::assertSame('The stream was stopped before the answer started.', $exception->getMessage());
        }
        self::assertFalse($stopSignal->isRequested());
        self::assertCount(0, $agent->getChatHistory()->getMessages());
        self::assertCount(1, $client->requests);
    }

    public function testTransportStopDoesNotSkipToolsOrPreventTheFollowingHttpRequest(): void
    {
        $stopSignal = new StopSignal(new InMemoryStorage(), 'conversation');
        $executed = [];
        $first = (new CallbackTool('first'))->setCallable(static function () use ($stopSignal, &$executed): string {
            $executed[] = 'first';
            $stopSignal->request();

            return 'First result';
        });
        $second = (new CallbackTool('second'))->setCallable(static function () use (&$executed): string {
            $executed[] = 'second';

            return 'Second result';
        });
        $body = 'data: {"id":"calls","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"id":"one","type":"function","function":{"name":"first","arguments":"{}"}},{"index":1,"id":"two","type":"function","function":{"name":"second","arguments":"{}"}}]},"finish_reason":"tool_calls"}]}' . "\n\n";
        $client = new FixtureHttpClient([new FixtureStream($body), new FixtureStream(self::openaiText())]);
        $agent = $this->agent(new OpenAI('fixture-key', 'fixture-model', httpClient: new StoppableHttpClient($client, $stopSignal->stopCallback(pollInterval: 0))));
        $agent->addTool($first)->addTool($second);
        $stopSignal->clear();

        try {
            foreach ($agent->stream(new UserMessage('Run tools')) as $chunk) {
            }
            self::fail('The following answer was stopped before any text.');
        } catch (ProviderException $exception) {
            self::assertSame('The stream was stopped before the answer started.', $exception->getMessage());
        }
        self::assertFalse($stopSignal->isRequested());
        self::assertSame(['first', 'second'], $executed);
        self::assertCount(2, $client->requests);
        $messages = $agent->getChatHistory()->getMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(UserMessage::class, $messages[0]);
    }

    public function testConfigurationAndNonStreamingRequestsPassThrough(): void
    {
        $stopSignal = new StopSignal(new InMemoryStorage(), 'conversation');
        $inner = new FixtureHttpClient([]);
        $client = new StoppableHttpClient($inner, $stopSignal->stopCallback());
        $request = HttpRequest::post('chat', ['message' => 'hello']);

        self::assertSame('normal request', $client->request($request)->body);
        self::assertSame([$request], $inner->requests);
        self::assertFalse($stopSignal->isRequested());
    }

    public function testNativeClientConsumesAStopBeforeCheckingNaturalEof(): void
    {
        $stopSignal = new StopSignal(new InMemoryStorage(), 'conversation');
        $empty = new FixtureStream('');
        $stopSignal->clear();
        $stream = (new StoppableHttpClient(new FixtureHttpClient([$empty]), $stopSignal->stopCallback()))->stream(HttpRequest::post('empty'));
        $stopSignal->request();

        self::assertTrue($stream->eof());
        self::assertSame(1, $empty->closes);
        self::assertFalse($stopSignal->isRequested());
    }

    public static function openaiText(): string
    {
        return 'data: {"id":"msg-1","choices":[{"index":0,"delta":{"content":"Partial"},"finish_reason":null}]}' . "\n\n"
            . 'data: {"id":"msg-1","choices":[{"index":0,"delta":{"content":" hidden"},"finish_reason":"stop"}]}' . "\n\n";
    }

    private function agent(AIProviderInterface $provider): Agent
    {
        $agent = (new Agent())->setThreadId('test-thread');
        $agent->setAiProvider($provider);

        return $agent;
    }
}
