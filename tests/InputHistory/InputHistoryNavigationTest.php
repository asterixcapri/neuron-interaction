<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\InputHistory;

use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class InputHistoryNavigationTest extends TestCase
{
    public function testTwoComposersRecallTheSameSequenceWithIndependentDraftsAndPositions(): void
    {
        $storage = new InMemoryStorage();
        $history = new InputHistory($storage);
        $first = new InputHistory($storage);
        $second = new InputHistory($storage);

        self::assertNull($first->older());
        self::assertNull($second->newer());

        $history->record('oldest');
        $history->record('middle');
        $history->record('newest');

        self::assertSame('newest', $first->older('first draft'));
        self::assertSame('middle', $first->older());
        self::assertSame('newest', $second->older('second draft'));
        self::assertSame('oldest', $first->older());
        self::assertSame('oldest', $first->older());
        self::assertSame('second draft', $second->newer());
        self::assertFalse($second->isNavigating());
        self::assertTrue($first->isNavigating());
        self::assertSame('middle', $first->newer());
        self::assertSame('newest', $first->newer());
        self::assertSame('first draft', $first->newer());
        self::assertNull($first->newer());
    }

    public function testLeavingNavigationDiscardsThePreviousDraftAndStartsAtNewest(): void
    {
        $history = new InputHistory(new InMemoryStorage());
        $history->record('remembered');
        $navigation = $history;

        self::assertSame('remembered', $navigation->older('discarded draft'));
        $navigation->leave();

        self::assertFalse($navigation->isNavigating());
        self::assertNull($navigation->newer());
        self::assertSame('remembered', $navigation->older());
        self::assertSame('', $navigation->newer());
        self::assertSame(['remembered'], $history->entries());
    }
}
