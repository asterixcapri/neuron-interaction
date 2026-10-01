<?php

declare(strict_types=1);

namespace NeuronInteraction\Message;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\UserMessage;

use function array_reverse;
use function is_array;

/** Compose preparation in registration order and display in reverse order. */
final class UserMessageProcessors implements UserMessageProcessorInterface
{
    /** @var list<UserMessageProcessorInterface> */
    private array $processors = [];

    /**
     * Register processors before running the host. Mutates this collection.
     *
     * @param UserMessageProcessorInterface|list<UserMessageProcessorInterface> $processors
     */
    public function addProcessor(UserMessageProcessorInterface|array $processors): self
    {
        foreach (is_array($processors) ? $processors : [$processors] as $processor) {
            $this->processors[] = self::requireProcessor($processor);
        }

        return $this;
    }

    /** @return list<UserMessageProcessorInterface> */
    public function all(): array
    {
        return $this->processors;
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
