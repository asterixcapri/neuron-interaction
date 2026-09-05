<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use PHPUnit\Framework\TestCase;

final class HelpAndLeaveTest extends TestCase
{
    public function testSharedCommandsUseTheAdaptersPresentationAndStopEffects(): void
    {
        $commands = new Commands([new HelpCommand('/guide'), new LeaveCommand('/quit')]);
        $adapter = new FakeCommandAdapter($commands);

        self::assertSame('completed', $commands->run('/guide', new CommandArguments(), $adapter)?->status);
        self::assertSame([
            '/guide — Lists what can be typed here.',
            '/quit — Stops the interaction.',
        ], $adapter->notices);
        self::assertFalse($adapter->stopped);
        self::assertSame('unknown', $commands->run('/missing', new CommandArguments(), $adapter)?->status);
        self::assertSame('completed', $commands->run('/quit', new CommandArguments(), $adapter)?->status);
        self::assertTrue($adapter->stopped);
    }
}
