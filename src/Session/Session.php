<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Storage\EntryStorageInterface;
use NeuronInteraction\Storage\MissingEntryException;
use UnexpectedValueException;

use function array_values;
use function hash;
use function is_array;
use function is_bool;
use function is_string;
use function trim;

/**
 * Owns its record and binds persisted messages to an Agent.
 * Hosts serialize turns, archive, clear and delete for one Session; operations
 * across message entries and this record are not a transaction.
 */
final readonly class Session
{
    private MessageStoreInterface $store;
    private string $namespace;

    public function __construct(
        private EntryStorageInterface $storage,
        private string $key,
        private string $userId,
        ?MessageStoreInterface $messageStore = null,
    ) {
        $this->namespace = self::namespaceForUser($userId);
        $this->store = $messageStore ?? new SessionMessageStore($storage, $userId);
    }

    /** @param array<string, string> $metadata */
    public static function create(EntryStorageInterface $storage, string $userId, array $metadata = [], ?MessageStoreInterface $messageStore = null): self
    {
        $now = self::now();
        $key = $storage->create(self::namespaceForUser($userId), [
            'title' => null,
            'createdAt' => $now,
            'lastUsedAt' => $now,
            'hasContent' => false,
            'metadata' => SessionMetadata::validate($metadata),
        ]);
        return new self($storage, $key, $userId, $messageStore);
    }

    public static function namespaceForUser(string $userId): string
    {
        return 'sessions-' . hash('sha256', $userId);
    }

    public function messageNamespace(): string
    {
        return 'messages-' . hash('sha256', $this->userId) . '-' . $this->key;
    }

    /** @return list<Message> */
    public function getMessages(?int $limit = null, ?string $before = null): array
    {
        return array_values($this->store->loadAll($this->key, $limit, $before));
    }

    public function bindToAgent(Agent $agent): Agent
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

    /** @phpstan-impure */
    public function getTitle(): ?string
    {
        $title = $this->record()['title'] ?? null;
        if ($title !== null && !is_string($title)) {
            throw new UnexpectedValueException('A Session title must be a string or null.');
        }
        return $title;
    }

    public function setTitle(string $title): void
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('A Session title cannot be blank.');
        }
        $this->storage->update($this->namespace, $this->key, [['path' => ['title'], 'value' => $title]]);
    }

    /** @return array<string, string>
     * @phpstan-impure
     */
    public function getMetadata(): array
    {
        $metadata = $this->record()['metadata'] ?? null;
        if (!is_array($metadata)) {
            throw new UnexpectedValueException('A Session record must contain application metadata.');
        }
        return SessionMetadata::validate($metadata);
    }

    public function setMetadata(string $key, string $value): void
    {
        SessionMetadata::key($key);
        $this->storage->update($this->namespace, $this->key, [['path' => ['metadata', $key], 'value' => $value]]);
    }

    public function removeMetadata(string $key): void
    {
        SessionMetadata::key($key);
        $this->storage->update($this->namespace, $this->key, removals: [['metadata', $key]]);
    }

    /** @phpstan-impure */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->date('createdAt');
    }

    /** @phpstan-impure */
    public function getLastUsedAt(): DateTimeImmutable
    {
        return $this->date('lastUsedAt');
    }

    /** @phpstan-impure */
    public function hasContent(): bool
    {
        $hasContent = $this->record()['hasContent'] ?? null;
        if (!is_bool($hasContent)) {
            throw new UnexpectedValueException('A Session record must contain a boolean content flag.');
        }
        return $hasContent;
    }

    /** Called only after successful message insertion, including direct Agent use. */
    public function registerUse(Message $message): void
    {
        $assignments = [['path' => ['lastUsedAt'], 'value' => self::now()]];
        if ($this->isPersonContent($message)) {
            $assignments[] = ['path' => ['hasContent'], 'value' => true];
        }
        $this->storage->update($this->namespace, $this->key, $assignments);
    }

    public function resetContent(): void
    {
        $this->storage->update($this->namespace, $this->key, [['path' => ['hasContent'], 'value' => false]]);
    }

    public function delete(): void
    {
        $this->store->clear($this->key);
        $this->storage->delete($this->namespace, $this->key);
    }

    private function isPersonContent(Message $message): bool
    {
        if (!$message instanceof UserMessage || $message instanceof ToolResultMessage) {
            return false;
        }
        if (trim($message->getContent() ?? '') !== '') {
            return true;
        }
        foreach ($message->getContentBlocks() as $block) {
            if (!$block instanceof TextContent) {
                return true;
            }
        }
        return false;
    }

    /** @return array<array-key, mixed> */
    private function record(): array
    {
        return $this->storage->get($this->namespace, $this->key)
            ?? throw new MissingEntryException('The Session no longer exists.');
    }

    private function date(string $field): DateTimeImmutable
    {
        $value = $this->record()[$field] ?? null;
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $value) : false;
        if ($date === false || $date->format('Y-m-d\TH:i:s.uP') !== $value) {
            throw new UnexpectedValueException('A Session record must contain a valid ' . $field . ' time.');
        }
        return $date;
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    }
}
