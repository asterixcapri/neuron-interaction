<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Interruption;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\HttpClient\StreamInterface;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class StopSignalHttpClientTest extends TestCase
{
    public function testTheSignalCallbackConnectsToNeuronsNativeClient(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'chat');
        $inner = $this->createMock(StreamInterface::class);
        $inner->method('eof')->willReturn(false);
        $inner->expects(self::once())->method('close');
        $http = $this->createStub(HttpClientInterface::class);
        $http->method('stream')->willReturn($inner);
        $client = new StoppableHttpClient($http, $signal->stopCallback(pollInterval: 0));
        $stream = $client->stream(HttpRequest::post('https://fixture.invalid/response'));
        self::assertFalse($stream->eof());
        $signal->request();
        self::assertTrue($stream->eof());
        self::assertFalse($signal->isRequested());
    }
}
