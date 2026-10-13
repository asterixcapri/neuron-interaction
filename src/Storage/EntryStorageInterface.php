<?php

declare(strict_types=1);

namespace NeuronInteraction\Storage;

/** Temporary name during the migration to the ordered entry contract. */
interface EntryStorageInterface
{
    /** @param array<array-key, mixed> $value */
    public function create(string $namespace, array $value, ?string $key = null): string;

    /** @return array<array-key, mixed>|null */
    public function get(string $namespace, string $key): ?array;

    /** @param array<array-key, mixed> $value */
    public function set(string $namespace, string $key, array $value): void;

    /**
     * Paths are literal field names, never dot notation or list indexes.
     * An empty request still requires an existing entry. Invalid requests are atomic.
     *
     * @param list<array{path: list<string>, value: mixed}> $assignments
     * @param list<list<string>> $removals
     */
    public function update(string $namespace, string $key, array $assignments = [], array $removals = []): void;

    public function delete(string $namespace, string $key): void;

    /**
     * Oldest insertion first; a limit selects the latest entries in that order.
     *
     * @return list<array{key: string, value: array<array-key, mixed>}>
     */
    public function list(string $namespace, ?int $limit = null): array;

    public function clear(string $namespace): void;
}
