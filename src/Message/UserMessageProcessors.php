<?php

declare(strict_types=1);

namespace NeuronInteraction\Message;

use NeuronAI\Chat\Messages\UserMessage;

use function array_reverse;

/** Compose preparation in registration order and display in reverse order. */
final class UserMessageProcessors implements UserMessageProcessorInterface
{
    /** @var list<UserMessageProcessorInterface> */
    private array $processors = [];

    public function __construct(UserMessageProcessorInterface ...$processors)
    {
        $this->addProcessor(...$processors);
    }

    public function addProcessor(UserMessageProcessorInterface ...$processors): self
    {
        foreach ($processors as $processor) {
            $this->processors[] = $processor;
        }

        return $this;
    }

    /** @return list<UserMessageProcessorInterface> */
    public function all(): array
    {
        return $this->processors;
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
