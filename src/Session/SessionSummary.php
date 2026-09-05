<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use DateTimeImmutable;
use InvalidArgumentException;

/** The recognition metadata of one non-empty Session at listing time. */
final readonly class SessionSummary
{
    public function __construct(
        public string $key,
        public DateTimeImmutable $lastUsedAt,
        public string $title,
        public ?int $size = null,
    ) {
        if ($this->size !== null && $this->size < 0) {
            throw new InvalidArgumentException(
                'A Session size cannot be negative.',
            );
        }
    }
}
