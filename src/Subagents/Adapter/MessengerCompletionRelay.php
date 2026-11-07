<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Adapter;

use NeuronInteraction\Subagents\SubagentStore;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

use function count;

/** Periodic wakeups are hints: durable work remains in SubagentStore until acknowledged. */
final readonly class MessengerCompletionRelay
{
    public function __construct(private SubagentStore $store, private MessageBusInterface $bus, private string $transport = 'parents') {}

    /** @phpstan-impure */
    public function dispatchPending(): int
    {
        $sessions = $this->store->pendingSessions();
        foreach ($sessions as $session) {
            $this->bus->dispatch(new ProcessCompletions($session['userId'], $session['sessionKey']), [new TransportNamesStamp([$this->transport])]);
        }
        return count($sessions);
    }
}
