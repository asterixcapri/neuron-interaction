<?php

declare(strict_types=1);

namespace NeuronInteraction\Message;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;
use UnexpectedValueException;

use function array_is_list;
use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/** Reuses Neuron's content-block and metadata deserialization. */
final class UserMessageFactory
{
    public static function fromMessage(Message $message): UserMessage
    {
        if ($message->getRole() !== 'user' || $message instanceof ToolResultMessage) {
            throw new UnexpectedValueException('Only ordinary messages with the user role are supported.');
        }

        if ($message instanceof UserMessage) {
            return $message;
        }

        $decoded = json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('A serialized message must be an object.');
        }

        $data = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new UnexpectedValueException('Serialized message fields must have string names.');
            }
            $data[$key] = $value;
        }

        return self::fromArray($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): UserMessage
    {
        if (($data['role'] ?? null) !== 'user' || isset($data['type']) || !array_key_exists('content', $data)) {
            throw new UnexpectedValueException('Only ordinary messages with the user role are supported.');
        }

        $content = $data['content'];
        if (!is_array($content) || !array_is_list($content)) {
            throw new UnexpectedValueException('A user message must contain a list of content blocks.');
        }

        try {
            $message = (new MessageDeserializer())->deserialize($data);
        } catch (Throwable $exception) {
            throw new UnexpectedValueException('Invalid user message.', previous: $exception);
        }

        if (!$message instanceof UserMessage) {
            throw new UnexpectedValueException('Only ordinary messages with the user role are supported.');
        }

        return $message;
    }
}
