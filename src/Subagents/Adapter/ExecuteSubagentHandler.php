<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Adapter;

use NeuronInteraction\Subagents\Executor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExecuteSubagentHandler
{
    public function __construct(private Executor $executor) {}

    public function __invoke(ExecuteSubagent $message): void
    {
        $this->executor->execute($message->runId);
    }
}
