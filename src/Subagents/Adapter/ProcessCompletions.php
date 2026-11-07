<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Adapter;

/** Trusted application message, not a client-supplied authorization claim. */
final readonly class ProcessCompletions
{
    public function __construct(public string $userId, public string $sessionKey) {}
}
