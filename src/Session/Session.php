<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Storage\StoredDocument;
use UnexpectedValueException;

use function array_is_list;
use function is_array;

/** A persisted conversation that can be installed directly as Chat History. */
final class Session extends AbstractChatHistory
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $namespace,
        private readonly string $key,
        private readonly string $userId,
        int $contextWindow = 50000,
        HistoryTrimmerInterface $trimmer = new HistoryTrimmer(),
        ?StoredDocument $document = null,
    ) {
        parent::__construct($contextWindow, $trimmer);

        $this->load($document);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    /** @return array<string, string> */
    public function getMetadata(): array
    {
        return SessionMetadata::decode($this->storage->read($this->namespace, $this->key)->metadata ?? []);
    }

    public function setMetadata(string $key, string $value): void
    {
        $field = SessionMetadata::key($key);
        $document = $this->storage->read($this->namespace, $this->key);
        $metadata = $document->metadata ?? [];
        $metadata[$field] = $value;
        $metadata['userId'] = $this->userId;
        $this->storage->write($this->namespace, $this->key, $document->data ?? $this->history, $metadata);
    }

    public function removeMetadata(string $key): void
    {
        $field = SessionMetadata::key($key);
        $document = $this->storage->read($this->namespace, $this->key);
        if ($document === null || !array_key_exists($field, $document->metadata)) {
            return;
        }

        $metadata = $document->metadata;
        unset($metadata[$field]);
        $this->storage->write($this->namespace, $this->key, $document->data, $metadata);
    }

    /** @param list<Message> $messages */
    protected function setMessages(array $messages): void
    {
        $this->persist($messages);
    }

    protected function clear(): void
    {
        $this->persist([]);
    }

    private function load(?StoredDocument $document): void
    {
        $document ??= $this->storage->read($this->namespace, $this->key);

        if ($document === null) {
            return;
        }

        $messages = $document->data;

        if (!array_is_list($messages)) {
            throw new UnexpectedValueException(
                'A stored Chat History must be a JSON array.',
            );
        }

        foreach ($messages as $message) {
            if (!is_array($message)) {
                throw new UnexpectedValueException(
                    'Every stored Chat History entry must be a JSON object.',
                );
            }
        }

        /** @var list<array<string, mixed>> $messages */
        $this->history = $this->deserializeMessages($messages);
    }

    /** @param list<Message> $messages */
    private function persist(array $messages): void
    {
        $lastUsedAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );

        $metadata = $this->storage->read($this->namespace, $this->key)->metadata ?? [];
        $metadata['userId'] = $this->userId;
        $metadata['lastUsedAt'] = $lastUsedAt->format('Y-m-d\TH:i:s.uP');

        $this->storage->write(
            $this->namespace,
            $this->key,
            $messages,
            $metadata,
        );
    }
}
