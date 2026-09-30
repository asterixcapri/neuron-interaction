<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Http;

use InvalidArgumentException;
use NeuronInteraction\Http\StopSignal;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StopSignalTest extends TestCase
{
    public function testCreatingACallbackDoesNotAccessStorage(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::never())->method('read');
        $storage->expects(self::never())->method('write');
        $storage->expects(self::never())->method('delete');
        $polls = 0;
        (new StopSignal($storage, 'chat'))->stopCallback(
            onPoll: static function () use (&$polls): void { ++$polls; },
        );
        self::assertSame(0, $polls);
    }

    public function testACallbackConsumesSuccessiveRequestsWithoutLatchingTheStop(): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'chat');
        $callback = $signal->stopCallback(pollInterval: 0);
        self::assertFalse($callback());
        $signal->request();
        self::assertTrue($callback());
        self::assertFalse($signal->isRequested());
        self::assertFalse($callback());
        $signal->request();
        self::assertTrue($callback());
        self::assertFalse($signal->isRequested());
    }

    public function testEachCallbackHasItsOwnPollingSchedule(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::exactly(2))->method('read')->willReturn(null);
        $signal = new StopSignal($storage, 'chat');
        $first = $signal->stopCallback(pollInterval: 3600);
        $second = $signal->stopCallback(pollInterval: 3600);
        self::assertFalse($first());
        self::assertFalse($first());
        self::assertFalse($second());
    }

    /** @return iterable<string, array{float}> */
    public static function invalidPollIntervals(): iterable
    {
        yield 'negative' => [-0.01];
        yield 'infinite' => [INF];
        yield 'negative infinite' => [-INF];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('invalidPollIntervals')]
    public function testInvalidPollingIntervalsAreRejected(float $interval): void
    {
        $signal = new StopSignal(new InMemoryStorage(), 'chat');
        $this->expectException(InvalidArgumentException::class);
        $signal->stopCallback(pollInterval: $interval);
    }
}
