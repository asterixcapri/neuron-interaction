<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronInteraction\Storage\StorageInterface;
use UnexpectedValueException;

use function is_array;
use function is_bool;
use function is_string;
use function usort;

/** Owns lookup and lifecycle delegation within one user's Session namespace. */
final readonly class SessionStore
{
    public function __construct(
        private StorageInterface $storage,
        private MessageStoreInterface $messageStore,
        private string $userId
    ) {}

    /** @param array<string, string> $metadata */
    public function create(array $metadata = []): Session
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $date = $now->format('Y-m-d\TH:i:s.uP');
        $key = $this->storage->create(Session::namespaceForUser($this->userId), [
            'title' => null,
            'createdAt' => $date,
            'lastUsedAt' => $date,
            'hasContent' => false,
            'metadata' => $metadata,
        ]);
        return new Session(
            storage: $this->storage,
            messageStore: $this->messageStore,
            key: $key,
            userId: $this->userId,
            title: null,
            createdAt: $now,
            lastUsedAt: $now,
            hasContent: false,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, string> $metadata
     * @return list<Session>
     * @phpstan-impure
     */
    public function list(array $metadata = []): array
    {
        $sessions = [];
        foreach ($this->storage->list(Session::namespaceForUser($this->userId)) as $entry) {
            $session = $this->session($entry['key'], $entry['value']);
            if (!$session->hasContent()) {
                continue;
            }
            foreach ($metadata as $field => $value) {
                if ($session->getMetadataValue($field) !== $value) {
                    continue 2;
                }
            }
            $sessions[] = $session;
        }
        usort($sessions, static fn(Session $one, Session $other): int
            => ($other->getLastUsedAt() <=> $one->getLastUsedAt()) ?: ($one->getKey() <=> $other->getKey()));
        return $sessions;
    }

    public function get(string $key): ?Session
    {
        $data = $this->storage->get(Session::namespaceForUser($this->userId), $key);
        if ($data === null) {
            return null;
        }
        return $this->session($key, $data);
    }

    public function delete(string $key): void
    {
        $this->get($key)?->delete();
    }
    /** @param array<array-key, mixed> $data */
    private function session(string $key, array $data): Session
    {
        $title = $data['title'] ?? null;
        if ($title !== null && !is_string($title)) {
            throw new UnexpectedValueException('A Session title must be a string or null.');
        }
        $hasContent = $data['hasContent'] ?? null;
        if (!is_bool($hasContent)) {
            throw new UnexpectedValueException('A Session record must contain a boolean content flag.');
        }
        $metadata = $data['metadata'] ?? null;
        if (!is_array($metadata)) {
            throw new UnexpectedValueException('A Session record must contain application metadata.');
        }
        return new Session(
            storage: $this->storage,
            messageStore: $this->messageStore,
            key: $key,
            userId: $this->userId,
            title: $title,
            createdAt: $this->date($data, 'createdAt'),
            lastUsedAt: $this->date($data, 'lastUsedAt'),
            hasContent: $hasContent,
            metadata: $this->metadata($metadata),
        );
    }

    /**
     * @param array<array-key, mixed> $metadata
     * @return array<string, string>
     */
    private function metadata(array $metadata): array
    {
        $validated = [];
        foreach ($metadata as $name => $item) {
            if (!is_string($name) || $name === '' || !is_string($item)) {
                throw new UnexpectedValueException('Session metadata requires non-empty string keys and string values.');
            }
            $validated[$name] = $item;
        }
        return $validated;
    }

    /** @param array<array-key, mixed> $data */
    private function date(array $data, string $field): DateTimeImmutable
    {
        $value = $data[$field] ?? null;
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $value) : false;
        if ($date === false || $date->format('Y-m-d\TH:i:s.uP') !== $value) {
            throw new UnexpectedValueException('A Session record must contain a valid ' . $field . ' time.');
        }
        return $date;
    }
}
