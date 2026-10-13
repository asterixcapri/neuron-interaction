<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronInteraction\Storage\StorageInterface;

use function usort;

/** Owns lookup and lifecycle delegation within one user's Session namespace. */
final readonly class SessionStore
{
    public function __construct(private StorageInterface $storage, private string $userId, private ?MessageStoreInterface $messageStore = null) {}

    /** @param array<string, string> $metadata */
    public function create(array $metadata = []): Session
    {
        return Session::create($this->storage, $this->userId, $metadata, $this->messageStore);
    }

    /**
     * @param array<string, string> $metadata
     * @return list<SessionSummary>
     * @phpstan-impure
     */
    public function list(array $metadata = []): array
    {
        $filter = SessionMetadata::validate($metadata);
        $sessions = [];
        foreach ($this->storage->list(Session::namespaceForUser($this->userId)) as $entry) {
            $record = SessionRecord::fromArray($entry['value']);
            if (!$record->hasContent) {
                continue;
            }
            foreach ($filter as $field => $value) {
                if (($record->metadata[$field] ?? null) !== $value) {
                    continue 2;
                }
            }
            $sessions[] = new SessionSummary($entry['key'], $record->lastUsedAt, $record->title);
        }
        usort($sessions, static fn(SessionSummary $one, SessionSummary $other): int
            => ($other->getLastUsedAt() <=> $one->getLastUsedAt()) ?: ($one->getKey() <=> $other->getKey()));
        return $sessions;
    }

    public function get(string $key): ?Session
    {
        if ($this->storage->get(Session::namespaceForUser($this->userId), $key) === null) {
            return null;
        }
        return $this->session($key);
    }

    public function delete(string $key): void
    {
        $this->get($key)?->delete();
    }

    private function session(string $key): Session
    {
        return new Session($this->storage, $key, $this->userId, $this->messageStore);
    }
}
