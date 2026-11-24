<?php

declare(strict_types=1);

namespace NeuronInteraction\Event;

use NeuronInteraction\Subagents\Run;

/** A delegated task was successfully dispatched to the background adapter. */
final readonly class SubagentDispatched implements EventInterface
{
    public function __construct(public Run $run) {}
}
