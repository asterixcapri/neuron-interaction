<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Adapter;

use NeuronInteraction\Subagents\BackgroundAdapterInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final readonly class MessengerAdapter implements BackgroundAdapterInterface
{
    public function __construct(private MessageBusInterface $bus, private string $transport = 'subagents') {}

    public function dispatch(string $runId): void
    {
        $this->bus->dispatch(new ExecuteSubagent($runId), [new TransportNamesStamp([$this->transport])]);
    }
}
