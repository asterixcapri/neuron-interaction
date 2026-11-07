<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Adapter;

use Closure;
use LogicException;
use NeuronInteraction\Conversation;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessCompletionsHandler
{
    /** @param Closure(string, string): Conversation $conversations Restore the authorized session and fresh agent/channel. */
    public function __construct(private Closure $conversations) {}

    public function __invoke(ProcessCompletions $message): void
    {
        $conversation = ($this->conversations)($message->userId, $message->sessionKey);
        if ($conversation->session()->getUserId() !== $message->userId
            || $conversation->session()->getKey() !== $message->sessionKey) {
            throw new LogicException('The factory returned a different session.');
        }
        foreach ($conversation->processCompletions() as $event) {
            // Consuming the stream drives execution and the configured Neuron channel.
        }
    }
}
