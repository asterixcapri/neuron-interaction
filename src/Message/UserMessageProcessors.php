<?php

declare(strict_types=1);

namespace NeuronInteraction\Message;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\UserMessage;

/** Compose preparation in registration order and display in reverse order. */
final readonly class UserMessageProcessors implements UserMessageProcessorInterface
{
    /** @var list<UserMessageProcessorInterface> */
    private array $processors;

    /** @param UserMessageProcessorInterface|list<UserMessageProcessorInterface> $processors */
    public function __construct(UserMessageProcessorInterface|array $processors = [])
    {
        $validated = [];
        foreach (is_array($processors) ? $processors : [$processors] as $processor) {
            $validated[] = self::requireProcessor($processor);
        }
        $this->processors = $validated;
    }

    private static function requireProcessor(mixed $processor): UserMessageProcessorInterface
    {
        if (!$processor instanceof UserMessageProcessorInterface) {
            throw new InvalidArgumentException('A user-message processor must implement UserMessageProcessorInterface.');
        }

        return $processor;
    }

    public function forAgent(UserMessage $input): UserMessage
    {
        $input = clone $input;

        foreach ($this->processors as $processor) {
            $input = $processor->forAgent($input);
        }

        return $input;
    }

    public function forDisplay(UserMessage $content): UserMessage
    {
        $content = clone $content;

        foreach (array_reverse($this->processors) as $processor) {
            $content = $processor->forDisplay($content);
        }

        return $content;
    }
}
