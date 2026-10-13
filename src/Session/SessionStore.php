<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronInteraction\Storage\EntryStorageInterface;

use function json_encode;
use function strlen;
use function usort;

use const JSON_THROW_ON_ERROR;

/** Owns lookup and lifecycle delegation within one user's Session namespace. */
final readonly class SessionStore
{
    public function __construct(private EntryStorageInterface $storage, private string $userId, private ?MessageStoreInterface $messageStore = null) {}

    /** @param array<string, string> $metadata */
    public function create(array $metadata = []): Session
    {
        return Session::create($this->storage, $this->userId, $metadata, $this->messageStore);
    }

    /**
     * @param array<string, string> $metadata
     * @return list<SessionSummary>
     */
    public function list(array $metadata = []): array
    {
        $filter = SessionMetadata::validate($metadata);
        $sessions = [];
        foreach ($this->storage->list(Session::namespaceForUser($this->userId)) as $entry) {
            $session = $this->session($entry['key']);
            if (!$session->hasContent()) {
                continue;
            }
            $application = $session->getMetadata();
            foreach ($filter as $field => $value) {
                if (($application[$field] ?? null) !== $value) {
                    continue 2;
                }
            }
            // Transitional history-size presentation remains until ticket 06.
            $size = strlen(json_encode($session->getMessages(), JSON_THROW_ON_ERROR));
            $sessions[] = new SessionSummary($entry['key'], $session->getLastUsedAt(), $session->getTitle(), $size);
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
