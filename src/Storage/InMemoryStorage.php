<?php

declare(strict_types=1);

namespace NeuronInteraction\Storage;

use InvalidArgumentException;

use function array_slice;
use function array_values;

/** Ordered entries and detached value snapshots held in memory. */
final class InMemoryStorage extends AbstractStorage
{
    /** @var array<string, array<string, array{key: string, value: array<array-key, mixed>}>> */
    private array $entries = [];

    public function create(string $namespace, array $value, ?string $key = null): string
    {
        $this->validateIdentifier($namespace);
        $value = $this->snapshotValue($value);
        if ($key === null) {
            do {
                $key = $this->generateKey();
            } while (isset($this->entries[$namespace]['key:' . $key]));
        }
        $this->validateIdentifier($key);
        if (isset($this->entries[$namespace]['key:' . $key])) {
            throw new DuplicateKeyException('The storage key already exists.');
        }
        $this->entries[$namespace]['key:' . $key] = ['key' => $key, 'value' => $value];

        return $key;
    }

    public function get(string $namespace, string $key): ?array
    {
        $this->validateIdentifiers($namespace, $key);
        $entry = $this->entries[$namespace]['key:' . $key] ?? null;

        return $entry === null ? null : $this->snapshotValue($entry['value']);
    }

    public function set(string $namespace, string $key, array $value): void
    {
        $this->validateIdentifiers($namespace, $key);
        $this->entries[$namespace]['key:' . $key] = ['key' => $key, 'value' => $this->snapshotValue($value)];
    }

    public function update(string $namespace, string $key, array $assignments = [], array $removals = []): bool
    {
        $this->validateIdentifiers($namespace, $key);
        $entry = $this->entries[$namespace]['key:' . $key] ?? null;
        if ($entry === null) {
            return false;
        }
        $this->entries[$namespace]['key:' . $key]['value'] = $this->applyUpdate($entry['value'], $assignments, $removals);

        return true;
    }

    public function delete(string $namespace, string $key): void
    {
        $this->validateIdentifiers($namespace, $key);
        unset($this->entries[$namespace]['key:' . $key]);
    }

    public function list(string $namespace, ?int $limit = null): array
    {
        $this->validateIdentifier($namespace);
        if ($limit !== null && $limit < 0) {
            throw new InvalidArgumentException('The storage list limit cannot be negative.');
        }
        $entries = array_values($this->entries[$namespace] ?? []);
        if ($limit !== null) {
            $entries = $limit === 0 ? [] : array_slice($entries, -$limit);
        }
        foreach ($entries as &$entry) {
            $entry['value'] = $this->snapshotValue($entry['value']);
        }

        return $entries;
    }

    public function clear(string $namespace): void
    {
        $this->validateIdentifier($namespace);
        unset($this->entries[$namespace]);
    }

}
