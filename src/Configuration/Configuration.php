<?php

declare(strict_types=1);

namespace NeuronInteraction\Configuration;

use InvalidArgumentException;
use JsonException;

final class Configuration
{
    /** @param array<array-key, mixed> $values */
    public function __construct(
        private readonly string $key,
        private readonly string $userId,
        private array $values = [],
    ) {
        self::guardJson($values);
        $this->values = self::snapshotArray($values);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->has($name) ? $this->values[$name] : $default;
    }

    public function set(string $name, mixed $value): void
    {
        self::guardJson($value);
        $this->values[$name] = is_array($value) ? self::snapshotArray($value) : $value;
    }

    public function remove(string $name): void
    {
        unset($this->values[$name]);
    }

    /** @return array<array-key, mixed> */
    public function all(): array
    {
        return $this->values;
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
