<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use InvalidArgumentException;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use PHPUnit\Framework\TestCase;

final class CommandsTest extends TestCase
{
    public function testRegistryPreservesOrderAndLookupIsExact(): void
    {
        $first = new HelpCommand('/review');
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
                new Commands(new HelpCommand($name));
                self::fail('Invalid identifier must be rejected');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('identifier', $exception->getMessage());
            }
        }
    }

    public function testLastRegistrationWinsWithoutChangingTheCommandsPosition(): void
    {
        $original = new HelpCommand('/review');
        $other = new HelpCommand('/other');
        $replacement = new HelpCommand('/review');
        $commands = new Commands($original, $other, $replacement);

        self::assertSame([$replacement, $other], $commands->all());
        self::assertSame($replacement, $commands->named('/review'));
    }

    public function testCommandsCanBeAddedOneAtATimeOrTogether(): void
    {
        $first = new HelpCommand('/first');
        $second = new HelpCommand('/second');
        $third = new HelpCommand('/third');
        $replacement = new HelpCommand('/first');
        $commands = new Commands();
        self::assertSame($commands, $commands->addCommand($first));
        $commands->addCommand($second, $third, $replacement);

        self::assertSame([$replacement, $second, $third], $commands->all());
        self::assertSame($replacement, $commands->named('/first'));
    }

    public function testAddedCommandsMustHaveValidIdentifiers(): void
    {
        $commands = new Commands();
        $this->expectException(InvalidArgumentException::class);
        $commands->addCommand(new HelpCommand('invalid'));
    }
}
