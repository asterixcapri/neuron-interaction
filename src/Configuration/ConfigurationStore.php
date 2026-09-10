<?php

declare(strict_types=1);

namespace NeuronInteraction\Configuration;

use InvalidArgumentException;
use JsonException;
use NeuronInteraction\Storage\StorageInterface;

final class ConfigurationStore
{
    // Direct preferences never read or overwrite legacy named configurations.
    private const string NAMESPACE = 'preferences';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $userId,
    ) {}

    public function read(string $key, mixed $fallback = null): mixed
    {
        $values = $this->entries();

        return array_key_exists($key, $values) ? $values[$key] : $fallback;
    }

    public function write(string $key, mixed $value): void
    {
        self::guardJson($value);
        $values = $this->entries();
        $values[$key] = $value;
        $this->storage->write(
            self::NAMESPACE,
            $this->storageKey(),
            self::snapshotArray($values),
            ['userId' => $this->userId],
        );
    }

    public function delete(string $key): void
    {
        $values = $this->entries();
        if (!array_key_exists($key, $values)) {
            return;
        }

        unset($values[$key]);
        $this->storage->write(
            self::NAMESPACE,
            $this->storageKey(),
            self::snapshotArray($values),
            ['userId' => $this->userId],
        );
    }

    /** @return array<array-key, mixed> */
    public function entries(): array
    {
        $document = $this->storage->read(self::NAMESPACE, $this->storageKey());

        if ($document === null || ($document->metadata['userId'] ?? null) !== $this->userId) {
            return [];
        }

        return self::snapshotArray($document->data);
    }

    private function storageKey(): string
    {
        return hash('sha256', $this->userId);
    }

    /**
     * Copy each validated value by value so nested PHP references cannot mutate it.
     *
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function snapshotArray(array $values): array
    {
        $snapshot = [];
        foreach ($values as $key => $value) {
            $snapshot[$key] = is_array($value) ? self::snapshotArray($value) : $value;
        }

        return $snapshot;
    }

    private static function guardJson(mixed $value): void
    {
        self::guardData($value);

        // Validate encoding before accepting the value into memory.
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Configuration values must be JSON-compatible data.',
                previous: $exception,
            );
        }
    }

    private static function guardData(mixed $value, int $depth = 0): void
    {
        if ($depth > 512) {
            throw new InvalidArgumentException('Configuration values exceed the JSON nesting limit.');
        }

        if (is_object($value)) {
            throw new InvalidArgumentException(
                'Configuration values must use PHP arrays rather than objects.',
            );
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::guardData($item, $depth + 1);
            }
        }
    }
}
