<?php

declare(strict_types=1);

namespace NeuronInteraction\Message;

use NeuronAI\Chat\Messages\UserMessage;

use function array_push;
use function array_reverse;
use function array_values;

/** Compose preparation in registration order and display in reverse order. */
final class MessageProcessors implements MessageProcessorInterface
{
    /** @var list<MessageProcessorInterface> */
    private array $processors;

    public function __construct(MessageProcessorInterface ...$processors)
    {
        $this->processors = array_values($processors);
    }

    public function addProcessor(MessageProcessorInterface ...$processors): self
    {
        array_push($this->processors, ...array_values($processors));

        return $this;
    }

    /** @return list<MessageProcessorInterface> */
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
