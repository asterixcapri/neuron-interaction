<?php

declare(strict_types=1);

namespace NeuronInteraction\Event;

use NeuronAI\Chat\Messages\Message;
use NeuronInteraction\Subagents\Run;

/** A saved parent turn, possibly without text; recovered replies are not streamed a second time. */
final readonly class SubagentCompleted implements EventInterface
{
    public function __construct(public Run $run, public Message $reply, public bool $recovered = false) {}
}
