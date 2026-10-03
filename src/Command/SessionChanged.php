<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use NeuronInteraction\Session\Session;

/** The Session selected by a Command, ready for host presentation. */
final readonly class SessionChanged
{
    public function __construct(public Session $session) {}
}
