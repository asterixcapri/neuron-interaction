<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/** A stable value and a textual label ready for presentation. */
final readonly class SelectionOption
{
    public function __construct(
        public string $value,
        public string $label,
        public ?string $description = null,
    ) {
    }
}
