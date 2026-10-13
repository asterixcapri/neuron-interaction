<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use DateTimeImmutable;
use NeuronInteraction\Session\SessionSummary;
use PHPUnit\Framework\TestCase;

final class SessionSummaryTest extends TestCase
{
    public function testSummaryRetainsRecognitionInformation(): void
    {
        $lastUsedAt = new DateTimeImmutable('2026-09-04T12:00:00+00:00');
        $summary = new SessionSummary('session-key', $lastUsedAt, 'The opening words');
        self::assertSame('session-key', $summary->getKey());
        self::assertSame($lastUsedAt, $summary->getLastUsedAt());
        self::assertSame('The opening words', $summary->getTitle());
        self::assertNull((new SessionSummary('untitled', $lastUsedAt, null))->getTitle());
    }
}
