<?php

declare(strict_types=1);

namespace NeuronInteraction\Http;

use Closure;
use InvalidArgumentException;
use NeuronInteraction\Storage\StorageInterface;
use UnexpectedValueException;

use function hrtime;
use function is_bool;
use function is_finite;

/** A shared stop signal under a key chosen by the Host Application. */
final readonly class StopSignal
{
    private const string NAMESPACE = 'response-stops';

    public function __construct(private StorageInterface $storage, private string $key) {}

    /**
     * Creates a polling callback for Neuron's native stoppable HTTP client.
     *
     * @param (Closure(): void)|null $onPoll Lets the Host process pending input.
     * @return Closure(): bool
     */
    public function stopCallback(?Closure $onPoll = null, float $pollInterval = 0.01): Closure
    {
        if (!is_finite($pollInterval) || $pollInterval < 0) {
            throw new InvalidArgumentException('The polling interval must be finite and non-negative.');
        }

        $nextPoll = 0.0;
        return function () use ($onPoll, $pollInterval, &$nextPoll): bool {
            $now = hrtime(true) / 1_000_000_000;
            if ($now < $nextPoll) {
                return false;
            }

            $nextPoll = $now + $pollInterval;
            $onPoll?->__invoke();
            if (!$this->isRequested()) {
                return false;
            }

            $this->clear();
            return true;
        };
    }

    public function request(): void
    {
        $this->storage->write(self::NAMESPACE, $this->key, ['requested' => true]);
    }

    public function isRequested(): bool
    {
        $document = $this->storage->read(self::NAMESPACE, $this->key);
        $requested = $document?->data['requested'] ?? false;
        if (!is_bool($requested)) {
            throw new UnexpectedValueException('A stop flag must contain a boolean requested value.');
        }

        return $requested;
    }

    public function clear(): void
    {
        $this->storage->delete(self::NAMESPACE, $this->key);
    }
}
