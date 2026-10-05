<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

final readonly class Notification
{
    public function __construct(public string $text, public NotificationLevel $level = NotificationLevel::Info) {}
}
