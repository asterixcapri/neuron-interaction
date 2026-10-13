<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\Message;
use UnexpectedValueException;

/** @internal Registers Session lifecycle changes when a Host supplies a message store. */
final readonly class SessionTrackingMessageStore implements MessageStoreInterface
{
    public function __construct(private Session $session, private MessageStoreInterface $store) {}

    public function loadActive(string $threadId): array
    {
        return $this->store->loadActive($this->thread($threadId));
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        return $this->store->loadAll($this->thread($threadId), $limit, $before);
    }

    public function append(string $threadId, Message $message): void
    {
        $thread = $this->thread($threadId);
        // The void append contract cannot report whether insertion was new.
        // Hosts serialize this check and append, as they do other Session workflows.
        foreach ($this->store->loadAll($thread) as $existing) {
            if ($existing->getId() === $message->getId()) {
                return;
            }
        }
        $this->store->append($thread, $message);
        $this->session->registerUse($message);
    }

    public function archive(string $threadId, int $count): void
    {
        if ($count > 0) {
            $this->store->archive($this->thread($threadId), $count);
        }
    }

    public function clear(string $threadId): void
    {
        $this->store->clear($this->thread($threadId));
        $this->session->resetContent();
    }

    private function thread(string $threadId): string
    {
        if ($threadId !== $this->session->getKey()) {
            throw new UnexpectedValueException('The message thread does not match its Session.');
        }
        // Reading the owning record also rejects deleted or foreign Sessions.
        $this->session->hasContent();
        return $this->session->messageNamespace();
    }
}
