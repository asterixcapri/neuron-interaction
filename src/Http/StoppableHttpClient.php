<?php

declare(strict_types=1);

namespace NeuronInteraction\Http;

use Closure;
use InvalidArgumentException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/** Decorates HTTP streams with a shared stop flag owned by the Host. */
final readonly class StoppableHttpClient implements HttpClientInterface
{
    /** @param (Closure(): void)|null $onPoll Lets the Host process pending input. */
    public function __construct(
        private HttpClientInterface $inner,
        private StopSignal $stopSignal,
        private ?Closure $onPoll = null,
        private float $pollInterval = 0.01,
    ) {
        if (!is_finite($pollInterval) || $pollInterval < 0) {
            throw new InvalidArgumentException('The polling interval must be finite and non-negative.');
        }
    }

    public function request(HttpRequest $request): HttpResponse
    {
        return $this->inner->request($request);
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        return new StoppableStream($this->inner->stream($request), $this->stopSignal, $this->onPoll, $this->pollInterval);
    }

    public function withBaseUri(string $baseUri): HttpClientInterface
    {
        return new self($this->inner->withBaseUri($baseUri), $this->stopSignal, $this->onPoll, $this->pollInterval);
    }

    public function withHeaders(array $headers): HttpClientInterface
    {
        return new self($this->inner->withHeaders($headers), $this->stopSignal, $this->onPoll, $this->pollInterval);
    }

    public function withTimeout(float $timeout): HttpClientInterface
    {
        return new self($this->inner->withTimeout($timeout), $this->stopSignal, $this->onPoll, $this->pollInterval);
    }
}
