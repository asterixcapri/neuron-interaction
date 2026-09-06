<?php

declare(strict_types=1);

namespace NeuronInteraction\Configuration;

use NeuronInteraction\Storage\StorageInterface;

final class ConfigurationStore
{
    private const string NAMESPACE = 'configurations';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $userId,
    ) {}

    /** @param array<array-key, mixed> $values */
    public function create(string $key, array $values = []): Configuration
    {
        $configuration = new Configuration($key, $this->userId, $values);
        $this->storage->create(
            self::NAMESPACE,
            $configuration->all(),
            ['userId' => $this->userId],
            $this->storageKey($key),
        );

        return $configuration;
    }

    public function read(string $key): ?Configuration
    {
        $document = $this->storage->read(self::NAMESPACE, $this->storageKey($key));

        if ($document === null || ($document->metadata['userId'] ?? null) !== $this->userId) {
            return null;
        }

        return new Configuration($key, $this->userId, $document->data);
    }

    public function save(Configuration $configuration): void
    {
        $this->storage->write(
            self::NAMESPACE,
            $this->storageKey($configuration->getKey()),
            $configuration->all(),
            ['userId' => $this->userId],
        );
    }

    public function delete(string $key): void
    {
        $this->storage->delete(self::NAMESPACE, $this->storageKey($key));
    }

    private function storageKey(string $key): string
    {
        // The length prefix makes the owner/key pair unambiguous.
        return hash('sha256', strlen($this->userId) . ':' . $this->userId . $key);
    }
}
