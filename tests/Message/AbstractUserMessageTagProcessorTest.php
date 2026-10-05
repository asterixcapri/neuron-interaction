<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Message;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Message\AbstractUserMessageTagProcessor;
use PHPUnit\Framework\TestCase;

final class AbstractUserMessageTagProcessorTest extends TestCase
{
    public function testItExpandsReferencesAndRestoresTheOriginalTextWithoutChangingOtherContent(): void
    {
        $processor = new class extends AbstractUserMessageTagProcessor {
            protected function tagName(): string
            {
                return 'file';
            }

            protected function contentFor(string $reference): ?string
            {
                return $reference === 'README.md' ? 'The file contents' : null;
            }
        };
        $text = (new TextContent('Use @README.md and @unknown'))->addMetadata('source', 'human');
        $image = (new ImageContent('https://example.com/image.png', SourceType::URL))->addMetadata('image', 'original');
        $message = new UserMessage([$text, $image]);
        $message->addMetadata('origin', 'human');

        $prepared = $processor->forAgent($message);
        $displayed = $processor->forDisplay($prepared);

        self::assertSame('Use @README.md and @unknown', $message->getContent());
        self::assertSame('Use <file name="README.md">The file contents</file> and @unknown', $prepared->getContent());
        self::assertSame('Use @README.md and @unknown', $displayed->getContent());
        self::assertSame('human', $displayed->getMetadata('origin'));
        $displayedText = $displayed->getContentBlocks()[0];
        $displayedImage = $displayed->getContentBlocks()[1];
        self::assertInstanceOf(TextContent::class, $displayedText);
        self::assertInstanceOf(ImageContent::class, $displayedImage);
        self::assertSame('human', $displayedText->getMetadata('source'));
        self::assertSame('original', $displayedImage->getMetadata('image'));
        self::assertNotSame($message, $prepared);
        self::assertNotSame($prepared, $displayed);
        self::assertNotSame($text, $prepared->getContentBlocks()[0]);
        self::assertNotSame($image, $prepared->getContentBlocks()[1]);
    }

    public function testItDoesNotExpandTheSameTextBlockTwice(): void
    {
        $processor = new class extends AbstractUserMessageTagProcessor {
            public int $calls = 0;

            protected function tagName(): string
            {
                return 'skill';
            }

            protected function referencePrefix(): string
            {
                return '/';
            }

            protected function contentFor(string $reference): string
            {
                ++$this->calls;

                return 'Expanded /' . $reference;
            }
        };
        $message = new UserMessage('Use /review');

        $prepared = $processor->forAgent($message);
        $again = $processor->forAgent($prepared);

        self::assertSame('Use <skill name="review">Expanded /review</skill>', $again->getContent());
        self::assertSame(1, $processor->calls);
        self::assertSame('Use /review', $processor->forDisplay($again)->getContent());
    }

    public function testItLeavesUnmatchedMessagesAlone(): void
    {
        $processor = new class extends AbstractUserMessageTagProcessor {
            protected function tagName(): string
            {
                return 'file';
            }

            protected function contentFor(string $reference): ?string
            {
                return null;
            }
        };
        $message = new UserMessage('Write to someone@example.com and mention @unknown');

        self::assertSame($message->jsonSerialize(), $processor->forAgent($message)->jsonSerialize());
        self::assertSame($message->jsonSerialize(), $processor->forDisplay($message)->jsonSerialize());
    }

    public function testTagNameAndAttributesAreConfiguredPerProcessor(): void
    {
        $processor = new class extends AbstractUserMessageTagProcessor {
            protected function tagName(): string
            {
                return 'context';
            }

            protected function contentFor(string $reference): string
            {
                return 'First line' . "\n" . 'Second line';
            }

            protected function attributesFor(string $reference): array
            {
                return ['name' => $reference, 'source' => 'docs'];
            }
        };

        $prepared = $processor->forAgent(new UserMessage('Read @notes'));

        self::assertSame('Read <context name="notes" source="docs">First line' . "\n" . 'Second line</context>', $prepared->getContent());
        self::assertSame('Read @notes', $processor->forDisplay($prepared)->getContent());
    }
}
