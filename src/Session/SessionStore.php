<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use DateTimeImmutable;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronInteraction\Storage\EntryStorageInterface;
use UnexpectedValueException;

use function is_array;
use function is_bool;
use function is_string;
use function usort;

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
     * @phpstan-impure
     */
    public function list(array $metadata = []): array
    {
        $filter = SessionMetadata::validate($metadata);
        $sessions = [];
        foreach ($this->storage->list(Session::namespaceForUser($this->userId)) as $entry) {
            $record = $entry['value'];
            $hasContent = $record['hasContent'] ?? null;
            if (!is_bool($hasContent)) {
                throw new UnexpectedValueException('A Session record must contain a boolean content flag.');
            }
            if (!$hasContent) {
                continue;
            }
            $application = $record['metadata'] ?? null;
            if (!is_array($application)) {
                throw new UnexpectedValueException('A Session record must contain application metadata.');
            }
            $application = SessionMetadata::validate($application);
            foreach ($filter as $field => $value) {
                if (($application[$field] ?? null) !== $value) {
                    continue 2;
                }
            }
            $title = $record['title'] ?? null;
            if ($title !== null && !is_string($title)) {
                throw new UnexpectedValueException('A Session title must be a string or null.');
            }
            $time = $record['lastUsedAt'] ?? null;
            $lastUsedAt = is_string($time) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $time) : false;
            if ($lastUsedAt === false || $lastUsedAt->format('Y-m-d\TH:i:s.uP') !== $time) {
                throw new UnexpectedValueException('A Session record must contain a valid lastUsedAt time.');
            }
            $sessions[] = new SessionSummary($entry['key'], $lastUsedAt, $title);
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
