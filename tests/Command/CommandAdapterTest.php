<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use PHPUnit\Framework\TestCase;

final class CommandAdapterTest extends TestCase
{
    public function testAnOrdinaryCommandUsesTheSharedAdapterWithoutATerminal(): void
    {
        $replacement = new Agent();
        $selection = new SelectionRequest('/inspect', 'Pick one', [
            new SelectionOption('chosen-value', 'Visible label', 'Description'),
        ]);
        $command = new class($replacement, $selection) implements CommandInterface {
            public function __construct(private Agent $replacement, private SelectionRequest $selection)
            {
            }

            public function name(): string
            {
                return '/inspect';
            }

            public function describe(): string
            {
                return 'Exercises the shared Command Adapter.';
            }

            /** @param CommandAdapterInterface<mixed> $adapter */
            public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
            {
                $adapter->say($adapter->commands()->all()[0]->name());
                $adapter->warn($arguments->text);
                $adapter->agent()->setChatHistory($adapter->sessions()->start());
                $adapter->useAgent($this->replacement);
                $adapter->promptAgent('A generated Agent prompt.');
                $adapter->requestSelection($this->selection);
                $adapter->say('The request has returned.');
                $adapter->stop();
            }
        };
        $commands = new Commands([$command]);
        $adapter = new FakeCommandAdapter($commands);
        $execution = $commands->run('/inspect', new CommandArguments('A warning.'), $adapter);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame(['/inspect', 'The request has returned.'], $adapter->notices);
        self::assertSame(['A warning.'], $adapter->warnings);
        self::assertSame(['A generated Agent prompt.'], $adapter->prompts);
        self::assertSame([$selection], $adapter->selections);
        self::assertSame($commands, $adapter->commands());
        self::assertSame($replacement, $adapter->agent());
        self::assertTrue($adapter->stopped);
    }
}
