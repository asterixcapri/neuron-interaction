<?php

declare(strict_types=1);

namespace NeuronInteraction\Storage;

use InvalidArgumentException;

use function array_slice;
use function array_values;
use function bin2hex;
use function random_bytes;

/** Temporary adapter name while legacy consumers retain InMemoryStorage. */
final class InMemoryEntryStorage implements EntryStorageInterface
{
    /** @var array<string, array<string, array{key: string, value: array<array-key, mixed>}>> */
    private array $entries = [];

    public function create(string $namespace, array $value, ?string $key = null): string
    {
        StorageValue::identifier($namespace);
        $value = StorageValue::snapshot($value);
        if ($key === null) {
            do {
                $key = bin2hex(random_bytes(16));
            } while (isset($this->entries[$namespace]['key:' . $key]));
        }
        StorageValue::identifier($key);
        if (isset($this->entries[$namespace]['key:' . $key])) {
            throw new DuplicateKeyException('The storage key already exists.');
        }
        $this->entries[$namespace]['key:' . $key] = ['key' => $key, 'value' => $value];

        return $key;
    }

    public function get(string $namespace, string $key): ?array
    {
        $this->identifiers($namespace, $key);
        $entry = $this->entries[$namespace]['key:' . $key] ?? null;

        return $entry === null ? null : StorageValue::snapshot($entry['value']);
    }

    public function set(string $namespace, string $key, array $value): void
    {
        $this->identifiers($namespace, $key);
        $this->entries[$namespace]['key:' . $key] = ['key' => $key, 'value' => StorageValue::snapshot($value)];
    }

    public function update(string $namespace, string $key, array $assignments = [], array $removals = []): void
    {
        $this->identifiers($namespace, $key);
        $entry = $this->entries[$namespace]['key:' . $key] ?? null;
        if ($entry === null) {
            throw new MissingEntryException('The storage entry does not exist.');
        }
        $this->entries[$namespace]['key:' . $key]['value'] = StorageUpdate::apply($entry['value'], $assignments, $removals);
    }

    public function delete(string $namespace, string $key): void
    {
        $this->identifiers($namespace, $key);
        unset($this->entries[$namespace]['key:' . $key]);
    }

    public function list(string $namespace, ?int $limit = null): array
    {
        StorageValue::identifier($namespace);
        if ($limit !== null && $limit < 0) {
            throw new InvalidArgumentException('The storage list limit cannot be negative.');
        }
        $entries = array_values($this->entries[$namespace] ?? []);
        if ($limit !== null) {
            $entries = $limit === 0 ? [] : array_slice($entries, -$limit);
        }
        foreach ($entries as &$entry) {
            $entry['value'] = StorageValue::snapshot($entry['value']);
        }

        return $entries;
    }

    public function clear(string $namespace): void
    {
        StorageValue::identifier($namespace);
        unset($this->entries[$namespace]);
    }

    private function identifiers(string $namespace, string $key): void
    {
        StorageValue::identifier($namespace);
        StorageValue::identifier($key);
    }
}
