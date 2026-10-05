<?php

declare(strict_types=1);

namespace NeuronInteraction\Message;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;

use function htmlspecialchars;
use function is_string;
use function preg_match;
use function preg_replace_callback;

use const ENT_QUOTES;
use const ENT_XML1;

/** Expands @name or /name references into named tags for the Agent. */
abstract class AbstractUserMessageTagProcessor implements UserMessageProcessorInterface
{
    private const string REFERENCE_PATTERN = '~(?<!\S)([@/])([A-Za-z0-9_-]+(?:[./][A-Za-z0-9_-]+)*)~';

    abstract protected function tagName(): string;

    protected function referencePrefix(): string
    {
        return '@';
    }

    abstract protected function contentFor(string $reference): ?string;

    /** @return array<string, string> */
    protected function attributesFor(string $reference): array
    {
        return ['name' => $reference];
    }

    final public function forAgent(UserMessage $message): UserMessage
    {
        $prepared = clone $message;

        foreach ($prepared->getContentBlocks() as $block) {
            if (!$block instanceof TextContent || is_string($block->getMetadata($this->originalTextKey()))) {
                continue;
            }

            $original = $block->getContent();
            $expanded = preg_replace_callback(
                self::REFERENCE_PATTERN,
                function (array $matches): string {
                    if ($matches[1] !== $this->referencePrefix()) {
                        return $matches[0];
                    }

                    $content = $this->contentFor($matches[2]);
                    if ($content === null) {
                        return $matches[0];
                    }

                    $attributes = '';
                    foreach ($this->attributesFor($matches[2]) as $name => $value) {
                        if (preg_match('~^[A-Za-z_][A-Za-z0-9_-]*$~D', $name) !== 1) {
                            throw new InvalidArgumentException('Invalid tag attribute name.');
                        }

                        $attributes .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"';
                    }

                    $tagName = $this->tagName();
                    if (preg_match('~^[A-Za-z][A-Za-z0-9_-]*$~D', $tagName) !== 1) {
                        throw new InvalidArgumentException('Invalid tag name.');
                    }

                    return '<' . $tagName . $attributes . '>' . $content . '</' . $tagName . '>';
                },
                $original,
            );

            if ($expanded !== null && $expanded !== $original) {
                $block->content = $expanded;
                $block->addMetadata($this->originalTextKey(), $original);
            }
        }

        return $prepared;
    }

    final public function forDisplay(UserMessage $message): UserMessage
    {
        $display = clone $message;

        foreach ($display->getContentBlocks() as $block) {
            if (!$block instanceof TextContent) {
                continue;
            }

            $original = $block->getMetadata($this->originalTextKey());
            if (is_string($original)) {
                $block->content = $original;
            }
        }

        return $display;
    }

    private function originalTextKey(): string
    {
        return self::class . ':' . static::class . ':' . $this->tagName() . ':' . $this->referencePrefix() . ':originalText';
    }
}
