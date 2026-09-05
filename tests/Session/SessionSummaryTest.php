<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use DateTimeImmutable;
use InvalidArgumentException;
use NeuronInteraction\Session\SessionSummary;
use PHPUnit\Framework\TestCase;

final class SessionSummaryTest extends TestCase
{
    public function testSizeCannotBeNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A Session size cannot be negative.',
        );

        new SessionSummary(
            'session-key',
            new DateTimeImmutable(),
            'The opening words',
            -1,
        );
    }
}
