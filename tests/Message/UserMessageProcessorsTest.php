<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Message;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Message\UserMessageProcessorInterface;
use NeuronInteraction\Message\UserMessageProcessors;
use PHPUnit\Framework\TestCase;

use function is_string;

final class UserMessageProcessorsTest extends TestCase
{
    public function testCompleteMessagesAreComposedInPreparationAndReverseDisplayOrder(): void
    {
        $original = new UserMessage(new FileContent('ZmlsZQ==', SourceType::BASE64, 'application/pdf', 'report.pdf'));
        $original->addMetadata('source', 'original input');
        $first = $this->processor('A');
        $second = $this->processor('B');
        $processing = (new UserMessageProcessors())->addProcessor([$first, $second]);

        $prepared = $processing->forAgent($original);
        $displayed = $processing->forDisplay($prepared);

        self::assertSame('AB', $prepared->getMetadata('prepared'));
        self::assertSame('BA', $displayed->getMetadata('displayed'));
        self::assertSame('original input', $displayed->getMetadata('source'));
        self::assertInstanceOf(FileContent::class, $displayed->getContentBlocks()[0]);
        self::assertSame('report.pdf', $displayed->getContentBlocks()[0]->filename);
        self::assertNull($original->getMetadata('prepared'));
        self::assertNull($prepared->getMetadata('displayed'));
        self::assertNotSame($original, $prepared);
        self::assertNotSame($prepared, $displayed);
    }

    public function testAnEmptyPipelineReturnsAnUnchangedCopy(): void
    {
        $original = new UserMessage('Original text');
        $processing = new UserMessageProcessors();

        self::assertEquals($original, $processing->forAgent($original));
        self::assertEquals($original, $processing->forDisplay($original));
        self::assertNotSame($original, $processing->forAgent($original));
        self::assertNotSame($original, $processing->forDisplay($original));
    }

    public function testIncrementalRegistrationJoinsTheSameOrderedPipeline(): void
    {
        $first = $this->processor('A');
        $second = $this->processor('B');
        $third = $this->processor('C');
        $fourth = $this->processor('D');
        $processing = (new UserMessageProcessors())->addProcessor($first);

        self::assertSame($processing, $processing->addProcessor($second));
        self::assertSame($processing, $processing->addProcessor([$third, $fourth]));
        self::assertSame([$first, $second, $third, $fourth], $processing->all());

        $original = new UserMessage('Original text');
        $prepared = $processing->forAgent($original);
        $displayed = $processing->forDisplay($prepared);

        self::assertSame('ABCD', $prepared->getMetadata('prepared'));
        self::assertSame('DCBA', $displayed->getMetadata('displayed'));
        self::assertNull($original->getMetadata('prepared'));
        self::assertNull($prepared->getMetadata('displayed'));
    }

    private function processor(string $label): UserMessageProcessorInterface
    {
        $processor = $this->createStub(UserMessageProcessorInterface::class);
        $processor->method('forAgent')->willReturnCallback(static function (UserMessage $message) use ($label): UserMessage {
            $result = clone $message;
            $previous = $message->getMetadata('prepared');
            $result->addMetadata('prepared', (is_string($previous) ? $previous : '') . $label);

            return $result;
        });
        $processor->method('forDisplay')->willReturnCallback(static function (UserMessage $message) use ($label): UserMessage {
            $result = clone $message;
            $previous = $message->getMetadata('displayed');
            $result->addMetadata('displayed', (is_string($previous) ? $previous : '') . $label);

            return $result;
        });

        return $processor;
    }
}
