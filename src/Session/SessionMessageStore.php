<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\History\PaginatesMessages;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronInteraction\Storage\StorageInterface;
use UnexpectedValueException;

/** Persists active and archived messages in the Session's storage document. */
final readonly class SessionMessageStore implements MessageStoreInterface
{
    use PaginatesMessages;

    public function __construct(
        private StorageInterface $storage,
        private string $namespace,
        private string $userId,
    ) {
    }

    public function loadActive(string $threadId): array
    {
        return $this->deserialize(array_values(array_filter(
            $this->read($threadId),
            static fn (array $entry): bool => !isset($entry['archived_at']),
        )));
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        return $this->paginate($this->deserialize($this->read($threadId)), $limit, $before);
    }

    public function append(string $threadId, Message $message): void
    {
        $entries = $this->read($threadId);
        foreach ($entries as $entry) {
            if ($entry['__id'] === $message->getId()) {
                return;
            }
        }
        $entries[] = $message->jsonSerialize();
        $this->write($threadId, $entries);
    }

    public function archive(string $threadId, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        $entries = $this->read($threadId);
        $archivedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
        foreach ($entries as $index => $entry) {
            if ($count <= 0) {
                break;
            }
            if (!isset($entry['archived_at'])) {
                $entries[$index]['archived_at'] = $archivedAt;
                $count--;
            }
        }
        $this->write($threadId, $entries);
    }

    public function clear(string $threadId): void
    {
        $this->write($threadId, []);
    }

    /** @return list<array<string, mixed>> */
    private function read(string $threadId): array
    {
        $document = $this->storage->read($this->namespace, $threadId);
        if ($document === null || ($document->metadata['userId'] ?? null) !== $this->userId) {
            throw new UnexpectedValueException('The Session does not exist or belongs to another user.');
        }
        if (!array_is_list($document->data)) {
            throw new UnexpectedValueException('A stored Chat History must be a JSON array.');
        }
        $entries = [];
        foreach ($document->data as $index => $entry) {
            if (!is_array($entry)) {
                throw new UnexpectedValueException('Every stored Chat History entry must be a JSON object.');
            }
            $fields = [];
            foreach ($entry as $key => $value) {
                if (!is_string($key)) {
                    throw new UnexpectedValueException('Stored message fields must have string names.');
                }
                $fields[$key] = $value;
            }
            // Legacy entries without IDs must retain the same identity on each read.
            $fields['__id'] ??= 'msg_' . hash('sha256', $threadId . ':' . $index . ':' . json_encode($fields, JSON_THROW_ON_ERROR));
            $entries[] = $fields;
        }
        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<Message>
     */
    private function deserialize(array $entries): array
    {
        $deserializer = new MessageDeserializer();
        return array_map(static function (array $entry) use ($deserializer): Message {
            unset($entry['archived_at']);
            return $deserializer->deserialize($entry);
        }, $entries);
    }

    /** @param list<array<string, mixed>> $entries */
    private function write(string $threadId, array $entries): void
    {
        $document = $this->storage->read($this->namespace, $threadId);
        if ($document === null || ($document->metadata['userId'] ?? null) !== $this->userId) {
            throw new UnexpectedValueException('The Session does not exist or belongs to another user.');
        }
        $metadata = $document->metadata;
        $metadata['lastUsedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
        $this->storage->write($this->namespace, $threadId, $entries, $metadata);
    }
}
