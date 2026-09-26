<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\InputHistory;

use NeuronAI\Chat\Messages\UserMessage;
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

        $history->record(new UserMessage('oldest'));
        $history->record(new UserMessage('middle'));
        $history->record(new UserMessage('newest'));

        self::assertSame('newest', $first->older(new UserMessage('first draft'))?->getContent());
        self::assertSame('middle', $first->older()?->getContent());
        self::assertSame('newest', $second->older(new UserMessage('second draft'))?->getContent());
        self::assertSame('oldest', $first->older()?->getContent());
        self::assertSame('oldest', $first->older()?->getContent());
        self::assertSame('second draft', $second->newer()?->getContent());
        self::assertFalse($second->isNavigating());
        self::assertTrue($first->isNavigating());
        self::assertSame('middle', $first->newer()?->getContent());
        self::assertSame('newest', $first->newer()?->getContent());
        self::assertSame('first draft', $first->newer()?->getContent());
        self::assertNull($first->newer());
    }

    public function testLeavingNavigationDiscardsThePreviousDraftAndStartsAtNewest(): void
    {
        $history = new InputHistory(new InMemoryStorage());
        $history->record(new UserMessage('remembered'));
        $navigation = $history;

        self::assertSame('remembered', $navigation->older(new UserMessage('discarded draft'))?->getContent());
        $navigation->leave();

        self::assertFalse($navigation->isNavigating());
        self::assertNull($navigation->newer());
        self::assertSame('remembered', $navigation->older()?->getContent());
        self::assertNull($navigation->newer()?->getContent());
        self::assertSame(['remembered'], array_map(static fn (UserMessage $message): ?string => $message->getContent(), $history->entries()));
    }
}
