<?php

declare(strict_types=1);

namespace NeuronInteraction\Http;

use Closure;
use NeuronAI\HttpClient\StreamInterface;

/** Signals ordinary EOF so the provider can finalize its own response. @internal */
final class StoppableStream implements StreamInterface
{
    private bool $closed = false;
    private float $nextPoll = 0.0;

    /** @param (Closure(): void)|null $onPoll */
    public function __construct(
        private readonly StreamInterface $inner,
        private readonly StopSignal $stopSignal,
        private readonly ?Closure $onPoll,
        private readonly float $pollInterval,
    ) {
    }

    public function eof(): bool
    {
        if ($this->closed || $this->inner->eof()) {
            return true;
        }

        $now = hrtime(true) / 1_000_000_000;
        if ($now >= $this->nextPoll) {
            $this->nextPoll = $now + $this->pollInterval;
            ($this->onPoll)?->__invoke();
            if ($this->stopSignal->isRequested()) {
                $this->close();
                $this->stopSignal->clear();

                return true;
            }
        }

        return $this->inner->eof();
    }

    public function read(int $length): string
    {
        return $this->closed ? '' : $this->inner->read($length);
    }

    public function readLine(): string
    {
        return $this->closed ? '' : $this->inner->readLine();
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->inner->close();
            $this->closed = true;
        }
    }
}
