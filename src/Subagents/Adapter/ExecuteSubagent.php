<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Adapter;

final readonly class ExecuteSubagent
{
    public function __construct(public string $runId) {}
}
