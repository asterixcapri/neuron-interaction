<?php

declare(strict_types=1);

namespace NeuronInteraction\Event;

use NeuronInteraction\Session\Session;

/** A generated title saved to the Session used by the completed turn. */
final readonly class SessionTitleChanged implements EventInterface
{
    public function __construct(public Session $session, public string $title) {}
}
