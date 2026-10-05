<?php

declare(strict_types=1);

namespace NeuronInteraction\Event;

use NeuronAI\Agent\Agent;

/** The Agent selected by a Command, ready for host presentation. */
final readonly class AgentChanged implements EventInterface
{
    public function __construct(public Agent $agent) {}
}
