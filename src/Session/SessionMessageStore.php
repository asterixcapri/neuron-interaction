<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\History\PaginatesMessages;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronInteraction\Storage\DuplicateKeyException;
use NeuronInteraction\Storage\EntryStorageInterface;
use UnexpectedValueException;

use function is_array;
use function is_bool;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

/**
 * Persists one message per entry, separately from its archive state.
 * Hosts serialize multi-operation history workflows for a Session.
 */
final readonly class SessionMessageStore implements MessageStoreInterface
{
    use PaginatesMessages;

    public function __construct(private EntryStorageInterface $storage, private string $userId) {}

    public function loadActive(string $threadId): array
    {
        $messages = [];
        foreach ($this->entries($threadId) as $entry) {
            if (!$entry['archived']) {
                $messages[] = $this->deserialize($entry['message']);
            }
        }
        return $messages;
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        $messages = [];
        foreach ($this->entries($threadId) as $entry) {
            $messages[] = $this->deserialize($entry['message']);
        }
        return $this->paginate($messages, $limit, $before);
    }

    public function append(string $threadId, Message $message): void
    {
        $session = $this->session($threadId);
        // Neuron serialization can include JSON objects such as empty tool inputs.
        $payload = json_decode(json_encode($message, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new UnexpectedValueException('A serialized message must be a JSON object.');
        }
        try {
            $this->storage->create($session->messageNamespace(), ['message' => $payload, 'archived' => false], $message->getId());
        } catch (DuplicateKeyException) {
            return;
        }
        $session->registerUse($message);
    }

    public function archive(string $threadId, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        $session = $this->session($threadId);
        foreach ($this->entries($threadId) as $entry) {
            if (!$entry['archived']) {
                $this->storage->update($session->messageNamespace(), $entry['key'], [['path' => ['archived'], 'value' => true]]);
                if (--$count === 0) {
                    break;
                }
            }
        }
    }

    public function clear(string $threadId): void
    {
        $session = $this->session($threadId);
        $this->storage->clear($session->messageNamespace());
        $session->resetContent();
    }

    private function session(string $threadId): Session
    {
        return (new SessionStore($this->storage, $this->userId))->get($threadId)
            ?? throw new UnexpectedValueException('The Session does not exist or belongs to another user.');
    }

    /** @return list<array{key: string, message: array<string, mixed>, archived: bool}> */
    private function entries(string $threadId): array
    {
        $session = $this->session($threadId);
        $entries = [];
        foreach ($this->storage->list($session->messageNamespace()) as $entry) {
            $value = $entry['value'];
            if (!isset($value['message'], $value['archived']) || !is_array($value['message']) || !is_bool($value['archived'])) {
                throw new UnexpectedValueException('A stored message requires a payload and boolean archive state.');
            }
            $fields = [];
            foreach ($value['message'] as $field => $data) {
                if (!is_string($field)) {
                    throw new UnexpectedValueException('Stored message fields must have string names.');
                }
                $fields[$field] = $data;
            }
            if (($fields['__id'] ?? null) !== $entry['key']) {
                throw new UnexpectedValueException('The stored message identity must match its key.');
            }
            $entries[] = ['key' => $entry['key'], 'message' => $fields, 'archived' => $value['archived']];
        }
        return $entries;
    }

    /** @param array<string, mixed> $payload */
    private function deserialize(array $payload): Message
    {
        return (new MessageDeserializer())->deserialize($payload);
    }
}
