<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronInteraction\Storage\StorageInterface;

/** A persisted conversation whose message store can be bound to an Agent. */
final readonly class Session
{
    private MessageStoreInterface $store;

    public function __construct(
        private StorageInterface $storage,
        private string $namespace,
        private string $key,
        private string $userId,
    ) {
        $this->store = new SessionMessageStore($storage, $namespace, $userId);
        $this->getMessages();
    }

    public function messageStore(): MessageStoreInterface
    {
        return $this->store;
    }

    /** @return list<Message> */
    public function getMessages(): array
    {
        return array_values($this->store->loadAll($this->key));
    }

    /** Returns an Agent copy bound to this conversation, retaining its context window. */
    public function bindTo(Agent $agent): Agent
    {
        return $agent->for($this->key)->setMessageStore($this->store);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Reads metadata that other Session instances can update.
     *
     * @phpstan-impure
     */
    public function getTitle(): ?string
    {
        return $this->getMetadata()['title'] ?? null;
    }

    public function setTitle(string $title): void
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('A Session title cannot be blank.');
        }

        if ($this->storage->read($this->namespace, $this->key) === null) {
            throw new InvalidArgumentException('The Session no longer exists.');
        }

        $this->setMetadata('title', $title);
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
        $this->storage->write($this->namespace, $this->key, $document->data ?? [], $metadata);
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

}
