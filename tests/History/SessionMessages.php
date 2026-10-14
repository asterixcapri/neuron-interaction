<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\History;

use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\StorageInterface;
use UnexpectedValueException;

/** Seeds message fixtures and their listing records using the supplied native store. */
final readonly class SessionMessages implements MessageStoreInterface
{
    private SessionStore $sessions;

    public function __construct(StorageInterface $storage, private MessageStoreInterface $messages, string $userId)
    {
        $this->sessions = new SessionStore($storage, $messages, $userId);
    }

    public function loadActive(string $threadId): array
    {
        return $this->messages->loadActive($this->session($threadId)->messageNamespace());
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        return $this->session($threadId)->getMessages($limit, $before);
    }

    public function append(string $threadId, Message $message): void
    {
        $session = $this->session($threadId);
        $this->messages->append($session->messageNamespace(), $message);
        SessionHistory::recordFixture($session, $message);
    }

    public function archive(string $threadId, int $count): void
    {
        $this->messages->archive($this->session($threadId)->messageNamespace(), $count);
    }

    public function clear(string $threadId): void
    {
        $session = $this->session($threadId);
        $this->messages->clear($session->messageNamespace());
        $session->resetContent();
    }

    private function session(string $threadId): Session
    {
        return $this->sessions->get($threadId)
            ?? throw new UnexpectedValueException('The Session does not exist or belongs to another user.');
    }
}
