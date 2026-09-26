<?php

declare(strict_types=1);

namespace NeuronInteraction\InputHistory;

use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;
use UnexpectedValueException;

/** @internal Reuses Neuron's content-block and metadata deserialization. */
final class UserMessageDeserializer extends InMemoryChatHistory
{
    /** @param array<string, mixed> $data */
    public function deserialize(array $data): UserMessage
    {
        if (($data['role'] ?? null) !== 'user' || isset($data['type']) || !array_key_exists('content', $data)) {
            throw new UnexpectedValueException('An input history message must be a user message.');
        }

        $content = $data['content'];
        if (!is_array($content) || !array_is_list($content)) {
            throw new UnexpectedValueException('An input history message must contain a list of content blocks.');
        }

        try {
            $message = $this->deserializeMessage($data);
        } catch (Throwable $exception) {
            throw new UnexpectedValueException('Invalid input history message.', previous: $exception);
        }

        if (!$message instanceof UserMessage) {
            throw new UnexpectedValueException('An input history message must be a user message.');
        }

        return $message;
    }
}
