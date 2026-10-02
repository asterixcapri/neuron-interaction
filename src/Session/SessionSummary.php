<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use DateTimeImmutable;
use InvalidArgumentException;

/** The recognition metadata of one non-empty Session at listing time. */
final readonly class SessionSummary
{
    public function __construct(
        private string $key,
        private DateTimeImmutable $lastUsedAt,
        private ?string $title,
        private ?int $size = null,
    ) {
        if ($this->size !== null && $this->size < 0) {
            throw new InvalidArgumentException(
                'A Session size cannot be negative.',
            );
        }
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getLastUsedAt(): DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

}
