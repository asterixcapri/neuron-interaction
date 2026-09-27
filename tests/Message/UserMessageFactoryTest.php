<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Message;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Message\UserMessageFactory;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class UserMessageFactoryTest extends TestCase
{
    public function testExistingUserMessageIsReturnedDirectly(): void
    {
        $message = new UserMessage('hello');

        self::assertSame($message, UserMessageFactory::fromMessage($message));
    }

    public function testGenericUserMessageIsConverted(): void
    {
        $message = new Message(MessageRole::USER, 'hello');
        $message->addMetadata('source', 'test');

        $converted = UserMessageFactory::fromMessage($message);

        self::assertSame($message->jsonSerialize(), $converted->jsonSerialize());
        self::assertNotSame($message->getContentBlocks()[0], $converted->getContentBlocks()[0]);
    }

    public function testArrayIsDeserialized(): void
    {
        $message = UserMessageFactory::fromArray([
            'role' => 'user',
            'content' => [['type' => 'text', 'content' => 'hello']],
            'source' => 'test',
        ]);

        self::assertSame('hello', $message->getContent());
        self::assertSame('test', $message->getMetadata('source'));
    }

    public function testAssistantMessageIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        UserMessageFactory::fromMessage(new AssistantMessage('hello'));
    }

    public function testToolResultIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        UserMessageFactory::fromMessage(new ToolResultMessage([]));
    }

    public function testOtherRoleInArrayIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        UserMessageFactory::fromArray(['role' => 'assistant', 'content' => []]);
    }
}
