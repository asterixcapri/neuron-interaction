<?php

declare(strict_types=1);

namespace NeuronInteraction\Storage;

/**
 * Persists JSON documents under namespaced logical keys.
 *
 * Adapters own JSON encoding, physical naming and discovery. Caller-owned
 * Metadata uses camelCase names and string values.
 */
interface StorageInterface
{
    /**
     * Creates a document with a supplied key or a new generated key.
     * An existing key fails without replacing its document.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, string> $metadata
     */
    public function create(
        string $namespace,
        array $data,
        array $metadata = [],
        ?string $key = null,
    ): StoredDocument;

    /** A missing document returns null without creating storage state. */
    public function read(
        string $namespace,
        string $key,
    ): ?StoredDocument;

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $metadata
     */
    public function write(
        string $namespace,
        string $key,
        array $data,
        array $metadata = [],
    ): StoredDocument;

    /** Removes a document if it exists. */
    public function delete(string $namespace, string $key): void;

    /**
     * The namespace's documents, in adapter-defined order.
     *
     * @param array<string, string> $metadata Exact AND filter.
     * @return iterable<StoredDocument>
     */
    public function entries(string $namespace, array $metadata = []): iterable;
}
