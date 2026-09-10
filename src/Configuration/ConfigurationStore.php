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
    ) {
    }

    /**
     * The fallback selects the exact PHP type; strings must also be non-empty.
     * A null fallback requests a non-empty string or null.
     *
     * @return ($fallback is null ? non-empty-string|null :
     *     ($fallback is string ? non-empty-string :
     *     ($fallback is int ? int :
     *     ($fallback is float ? float :
     *     ($fallback is bool ? bool : array<array-key, mixed>)))))
     */
    public function read(string $key, mixed $fallback = null): mixed
    {
        self::guardJson($fallback);
        if ($fallback === '') {
            throw new InvalidArgumentException('A string fallback must be non-empty.');
        }

        $values = $this->entries();

        $value = $values[$key] ?? null;

        return match (true) {
            $fallback === null => is_string($value) && $value !== '' ? $value : null,
            is_string($fallback) => is_string($value) && $value !== '' ? $value : $fallback,
            is_int($fallback) => is_int($value) ? $value : $fallback,
            is_float($fallback) => is_float($value) ? $value : $fallback,
            is_bool($fallback) => is_bool($value) ? $value : $fallback,
            is_array($fallback) => is_array($value) ? $value : self::snapshotArray($fallback),
            default => throw new InvalidArgumentException('Unsupported configuration fallback type.'),
        };
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
