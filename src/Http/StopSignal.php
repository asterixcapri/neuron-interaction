<?php

declare(strict_types=1);

namespace NeuronInteraction\Http;

use NeuronInteraction\Storage\StorageInterface;
use UnexpectedValueException;

/** A shared stop signal under a key chosen by the Host Application. */
final readonly class StopSignal
{
    private const string NAMESPACE = 'response-stops';

    public function __construct(private StorageInterface $storage, private string $key)
    {
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
