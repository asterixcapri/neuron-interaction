<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use DateTimeImmutable;

/** The recognition metadata of one non-empty Session at listing time. */
final readonly class SessionSummary
{
    public function __construct(
        private string $key,
        private DateTimeImmutable $lastUsedAt,
        private ?string $title,
    ) {}

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

}
