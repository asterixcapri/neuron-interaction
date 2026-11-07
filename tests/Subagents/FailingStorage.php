<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Subagents;

use NeuronInteraction\Storage\StorageInterface;
use RuntimeException;

/** Simulates losing the process just before completion acknowledgement is persisted. */
final class FailingStorage implements StorageInterface
{
    public bool $failAcknowledgement = true;
    public function __construct(private StorageInterface $inner) {}
    public function create(string $namespace, array $value, ?string $key = null): string
    {
        return $this->inner->create($namespace, $value, $key);
    }
    public function get(string $namespace, string $key): ?array
    {
        return $this->inner->get($namespace, $key);
    }
    public function set(string $namespace, string $key, array $value): void
    {
        $this->inner->set($namespace, $key, $value);
    }
    public function update(string $namespace, string $key, array $assignments = [], array $removals = []): bool
    {
        if ($this->failAcknowledgement && $namespace === 'subagent-runs') {
            foreach ($assignments as $assignment) {
                if ($assignment['path'] === ['acknowledged']) {
                    $this->failAcknowledgement = false;
                    throw new RuntimeException('Crash before acknowledgement');
                }
            }
        }
        return $this->inner->update($namespace, $key, $assignments, $removals);
    }
    public function delete(string $namespace, string $key): void
    {
        $this->inner->delete($namespace, $key);
    }
    public function list(string $namespace, ?int $limit = null): array
    {
        return $this->inner->list($namespace, $limit);
    }
    public function clear(string $namespace): void
    {
        $this->inner->clear($namespace);
    }
}
