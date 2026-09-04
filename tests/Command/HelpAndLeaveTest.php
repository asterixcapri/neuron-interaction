<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Examples\BackendControls;
use NeuronInteraction\Session\Sessions;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class HelpAndLeaveTest extends TestCase
{
    public function testSharedCommandsWorkWithFakeAndBackendControls(): void
    {
        $commands = new Commands([new HelpCommand('/guide'), new LeaveCommand('/quit')]);
        $adapters = [
            new FakeCommandControls($commands),
            new BackendControls(new Agent(), $commands, new Sessions(new InMemoryStorage()), static function (): void {}),
        ];

        foreach ($adapters as $controls) {
            self::assertSame('completed', $commands->run('/guide', new CommandArguments(), $controls)->status);
            self::assertSame([
                '/guide — Lists what can be typed here.',
                '/quit — Stops the interaction.',
            ], $controls->notices);
            self::assertFalse($controls->stopped);
            self::assertSame('unknown', $commands->run('/missing', new CommandArguments(), $controls)->status);
            self::assertSame('completed', $commands->run('/quit', new CommandArguments(), $controls)->status);
            self::assertTrue($controls->stopped);
        }
    }
}
