<?php

declare(strict_types=1);

namespace NeuronInteraction\Configuration;

use InvalidArgumentException;
use JsonException;
use NeuronInteraction\Storage\DuplicateKeyException;
use NeuronInteraction\Storage\MissingEntryException;
use NeuronInteraction\Storage\StorageInterface;
use UnexpectedValueException;

use function hash;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;

final class ConfigurationStore
{
    private const string NAMESPACE = 'preferences';

    // Keep numeric preference names as object fields despite PHP array-key coercion.
    private const string FIELD_PREFIX = 'preference-';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $userId,
    ) {}

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
    public function get(string $key, mixed $fallback = null): mixed
    {
        self::guardJson($fallback);
        if ($fallback === '') {
            throw new InvalidArgumentException('A string fallback must be non-empty.');
        }

        $values = $this->list();

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

    public function set(string $key, mixed $value): void
    {
        self::guardJson($value);
        try {
            $this->storage->create(self::NAMESPACE, [], $this->storageKey());
        } catch (DuplicateKeyException) {
            // Another writer has already established this user's record.
        }

        $this->storage->update(
            self::NAMESPACE,
            $this->storageKey(),
            [['path' => [self::FIELD_PREFIX . $key], 'value' => $value]],
        );
    }

    public function delete(string $key): void
    {
        if ($this->storage->get(self::NAMESPACE, $this->storageKey()) === null) {
            return;
        }

        try {
            $this->storage->update(self::NAMESPACE, $this->storageKey(), removals: [[self::FIELD_PREFIX . $key]]);
        } catch (MissingEntryException) {
            // Removing a preference from an absent record is already complete.
        }
    }

    /** @return array<array-key, mixed> */
    public function list(): array
    {
        $preferences = [];
        foreach ($this->storage->get(self::NAMESPACE, $this->storageKey()) ?? [] as $field => $value) {
            if (!is_string($field) || !str_starts_with($field, self::FIELD_PREFIX)) {
                throw new UnexpectedValueException('A stored preference field must use its encoded name.');
            }
            $preferences[substr($field, strlen(self::FIELD_PREFIX))] = $value;
        }

        return $preferences;
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
