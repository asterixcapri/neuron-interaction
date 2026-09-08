<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandControlsAdapterInterface;
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
        $command = new class($selection) implements CommandInterface {
            public function __construct(private SelectionRequest $selection)
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

            /** @param CommandControlsAdapterInterface<mixed> $controls */
            public function run(CommandControlsAdapterInterface $controls, CommandArguments $arguments): void
            {
                $controls->say($controls->commands()->all()[0]->name());
                $controls->warn($arguments->text);
$replacement = $controls->createAgent();
                $replacement->setChatHistory($controls->sessionStore()->create());
                $controls->useAgent($replacement);
                $controls->promptAgent('A generated Agent prompt.');
                $controls->requestSelection($this->selection);
                $controls->say('The request has returned.');
                $controls->stop();
            }
        };
        $commands = new Commands([$command]);
        $adapter = new FakeCommandAdapter($commands);
        $adapter->configurationStore()->create('global', ['agent' => 'test']);
        $adapter->factory = static fn (): Agent => $replacement;
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
