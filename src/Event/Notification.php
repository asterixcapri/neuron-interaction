<?php

declare(strict_types=1);

namespace NeuronInteraction\Event;

use NeuronInteraction\Command\NotificationLevel;

final readonly class Notification implements EventInterface
{
    public function __construct(public string $text, public NotificationLevel $level = NotificationLevel::Info) {}
}
