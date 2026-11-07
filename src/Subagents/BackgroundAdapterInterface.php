<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents;

interface BackgroundAdapterInterface
{
    /** Schedule execution and return without waiting for the LLM result. */
    public function dispatch(string $runId): void;
}
