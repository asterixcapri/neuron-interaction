<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronInteraction\Storage\StorageInterface;
use RuntimeException;

use function array_values;
use function hash;
use function trim;

/**
 * Owns its record and binds persisted messages to an Agent.
 * Hosts serialize turns, archive, clear and delete for one Session; operations
 * across message entries and this record are not a transaction.
 */
final class Session
{
    private readonly string $namespace;
    /** @param array<string, string> $metadata */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly MessageStoreInterface $messageStore,
        private readonly string $key,
        private readonly string $userId,
        private ?string $title,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $lastUsedAt,
        private bool $hasContent,
        private array $metadata,
    ) {
        $this->namespace = self::namespaceForUser($userId);
    }

    public static function namespaceForUser(string $userId): string
    {
        return 'sessions-' . hash('sha256', $userId);
    }

    public function messageNamespace(): string
    {
        return 'messages-' . hash('sha256', $this->userId) . '-' . $this->key;
    }

    /**
     * @return list<Message>
     * @phpstan-impure
     */
    public function getMessages(?int $limit = null, ?string $before = null): array
    {
        $this->assertExists();
        return array_values($this->messageStore->loadAll($this->messageNamespace(), $limit, $before));
    }

    public function bindToAgent(Agent $agent): Agent
    {
        $this->assertExists();
        return $agent->for($this->messageNamespace())->setMessageStore($this->messageStore);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('A Session title cannot be blank.');
        }
        $this->updateRecord([['path' => ['title'], 'value' => $title]]);
        $this->title = $title;
    }

    /** @return array<string, string> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMetadataValue(string $key): ?string
    {
        return $this->metadata[$key] ?? null;
    }

    public function setMetadata(string $key, string $value): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Metadata key cannot be empty.');
        }
        $this->updateRecord([['path' => ['metadata', $key], 'value' => $value]]);
        $this->metadata[$key] = $value;
    }

    public function removeMetadata(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Metadata key cannot be empty.');
        }
        $this->updateRecord(removals: [['metadata', $key]]);
        unset($this->metadata[$key]);
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function hasContent(): bool
    {
        return $this->hasContent;
    }

    /** @internal Checks the stored record before message operations. */
    public function assertExists(): void
    {
        if ($this->storage->get($this->namespace, $this->key) === null) {
            throw new RuntimeException('The Session no longer exists.');
        }
    }

    /** Records a successfully completed Conversation turn. */
    public function registerUse(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updateRecord([
            ['path' => ['lastUsedAt'], 'value' => $now->format('Y-m-d\TH:i:s.uP')],
            ['path' => ['hasContent'], 'value' => true],
        ]);
        $this->lastUsedAt = $now;
        $this->hasContent = true;
    }

    /** Resets listing visibility after the native message history has been cleared. */
    public function resetContent(): void
    {
        $this->updateRecord([['path' => ['hasContent'], 'value' => false]]);
        $this->hasContent = false;
    }

    public function delete(): void
    {
        $this->assertExists();
        $this->messageStore->clear($this->messageNamespace());
        $this->resetContent();
        $this->storage->delete($this->namespace, $this->key);
    }

    /**
     * @param list<array{path: list<string>, value: mixed}> $assignments
     * @param list<list<string>> $removals
     */
    private function updateRecord(array $assignments = [], array $removals = []): void
    {
        if (!$this->storage->update($this->namespace, $this->key, $assignments, $removals)) {
            throw new RuntimeException('The Session no longer exists.');
        }
    }

}
