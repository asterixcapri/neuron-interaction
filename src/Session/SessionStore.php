<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use DateTimeImmutable;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Storage\StoredDocument;
use UnexpectedValueException;

/**
 * Owns the lifecycle of conversations persisted through shared storage.
 */
final readonly class SessionStore
{
    private const string NAMESPACE = 'sessions';

    public function __construct(private StorageInterface $storage, private string $userId) {}

    /**
     * Starts a distinct Session with an empty History.
     *
     * @param array<string, string> $metadata
     */
    public function create(array $metadata = []): Session
    {
        $document = $this->storage->create(
            self::NAMESPACE,
            [],
            ['userId' => $this->userId] + SessionMetadata::encode($metadata),
        );

        return $this->session($document);
    }

    /**
     * Returns non-empty Sessions, most recently used first.
     *
     * @param array<string, string> $metadata
     * @return list<SessionSummary>
     */
    public function summaries(array $metadata = []): array
    {
        $sessions = [];

        $filter = ['userId' => $this->userId] + SessionMetadata::encode($metadata);

        foreach ($this->storage->entries(self::NAMESPACE, $filter) as $document) {
            if (($document->metadata['userId'] ?? null) !== $this->userId) {
                continue;
            }

            $title = $this->title($this->session($document));

            if ($title === null) {
                continue;
            }

            $sessions[] = new SessionSummary(
                $document->key,
                $this->lastUsedAt($document),
                $title,
                $document->size(),
            );
        }

        usort(
            $sessions,
            static fn (SessionSummary $one, SessionSummary $other): int =>
                ($other->lastUsedAt <=> $one->lastUsedAt)
                    ?: ($one->key <=> $other->key),
        );

        return $sessions;
    }

    /** Reads only Sessions owned by this Store's user. */
    public function read(string $key): ?Session
    {
        $document = $this->storage->read(self::NAMESPACE, $key);

        if ($document === null || ($document->metadata['userId'] ?? null) !== $this->userId) {
            return null;
        }

        return $this->session($document);
    }

    public function delete(string $key): void
    {
        if ($this->read($key) !== null) {
            $this->storage->delete(self::NAMESPACE, $key);
        }
    }

    private function session(StoredDocument $document): Session
    {
        return new Session(
            $this->storage,
            self::NAMESPACE,
            $document->key,
            $this->userId,
            document: $document,
        );
    }

    private function title(ChatHistoryInterface $history): ?string
    {
        foreach ($history->getMessages() as $message) {
            // Neuron represents tool results with the user role, but their
            // content was not authored by the person.
            if ($message->getRole() !== MessageRole::USER->value
                || $message instanceof ToolResultMessage) {
                continue;
            }

            $content = $message->getContent();

            if ($content !== null && trim($content) !== '') {
                return $content;
            }
        }

        return null;
    }

    private function lastUsedAt(StoredDocument $document): DateTimeImmutable
    {
        $value = $document->metadata['lastUsedAt'] ?? null;
        $lastUsedAt = $value === null
            ? false
            : DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.uP',
                $value,
            );

        if ($lastUsedAt === false) {
            throw new UnexpectedValueException(
                'A stored Session must contain a valid last-used time.',
            );
        }

        return $lastUsedAt;
    }
}
