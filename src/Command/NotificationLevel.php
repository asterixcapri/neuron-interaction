<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

enum NotificationLevel
{
    case Info;
    case Warning;
    case Error;
}
