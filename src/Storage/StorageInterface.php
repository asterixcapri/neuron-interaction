<?php

declare(strict_types=1);

namespace NeuronInteraction\Storage;

use InvalidArgumentException;
use NeuronInteraction\Exception\DuplicateKeyException;

/**
 * Ordered namespaced JSON-array entries with atomic individual operations.
 * Stored and returned values are detached snapshots. No cross-entry transactions.
 */
interface StorageInterface
{
    /**
     * Insert without replacement and return the supplied or generated opaque key.
     *
     * @param array<array-key, mixed> $value
     * @throws InvalidArgumentException when identifiers or values are invalid
     * @throws DuplicateKeyException when a supplied key already exists
     */
    public function create(string $namespace, array $value, ?string $key = null): string;

    /**
     * Missing reads return null without establishing storage state.
     *
     * @return array<array-key, mixed>|null
     */
    public function get(string $namespace, string $key): ?array;

    /**
     * Insert or replace the entire value, preserving an existing insertion position.
     *
     * @param array<array-key, mixed> $value
     */
    public function set(string $namespace, string $key, array $value): void;

    /**
     * Atomically assign or remove fields. Null assignment retains a field.
     * Paths are literal field names, never dot notation or list indexes.
     * Returns false when the target is absent; never creates an entry.
     * Returns true for existing entries, including empty or unchanged updates.
     * Invalid requests are atomic.
     *
     * @param list<array{path: list<string>, value: mixed}> $assignments
     * @param list<list<string>> $removals
     * @throws InvalidArgumentException when identifiers, paths or values are invalid
     */
    public function update(string $namespace, string $key, array $assignments = [], array $removals = []): bool;

    /** Idempotent removal; recreation gives the key a new insertion position. */
    public function delete(string $namespace, string $key): void;

    /**
     * Oldest insertion first; a positive limit selects the latest entries in that order.
     * Zero returns none; negative limits fail. Keys remain exact strings.
     * A bounded result may still require scanning the entire namespace.
     *
     * @return list<array{key: string, value: array<array-key, mixed>}>
     */
    public function list(string $namespace, ?int $limit = null): array;

    /** Idempotent removal of this namespace's entries, without affecting others. */
    public function clear(string $namespace): void;
}
