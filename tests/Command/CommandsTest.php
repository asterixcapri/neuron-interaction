<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use InvalidArgumentException;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use PHPUnit\Framework\TestCase;

final class CommandsTest extends TestCase
{
    public function testRegistryPreservesOrderAndLookupIsExact(): void
    {
        $first = new ClearCommand('/review');
        $second = new HelpCommand('/Review');
        $commands = new Commands($first, $second);
        self::assertSame([$first, $second], $commands->all());
        self::assertSame($first, $commands->named('/review'));
        self::assertSame($second, $commands->named('/Review'));
        self::assertNull($commands->named('review'));
        self::assertNull($commands->named('/missing'));
        self::assertSame([], (new Commands())->all());
    }

    public function testInvalidIdentifiersFailBeforeAnyExecution(): void
    {
        foreach (['review', '', '/', '//review', '/two words', '/review!'] as $name) {
            try {
                new Commands(new ClearCommand($name));
                self::fail('Invalid identifier must be rejected');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('identifier', $exception->getMessage());
            }
        }
    }

    public function testDuplicatesAreRejectedRatherThanShadowed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Commands(new ClearCommand('/review'), new HelpCommand('/review'));
    }
}
