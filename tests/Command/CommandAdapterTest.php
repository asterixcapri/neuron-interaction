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

            /** @param CommandControlsAdapterInterface<mixed> $adapter */
            public function run(CommandControlsAdapterInterface $adapter, CommandArguments $arguments): void
            {
                $adapter->say($adapter->commands()->all()[0]->name());
                $adapter->warn($arguments->text);
                $configuration = $adapter->configurationStore()->read('global');
                if ($configuration === null) {
                    throw new \RuntimeException('Missing test configuration.');
                }
                $replacement = $adapter->agentFactoryRegistry()->create($configuration);
                $replacement->setChatHistory($adapter->sessionStore()->create());
                $adapter->useAgent($replacement);
                $adapter->promptAgent('A generated Agent prompt.');
                $adapter->requestSelection($this->selection);
                $adapter->say('The request has returned.');
                $adapter->stop();
            }
        };
        $commands = new Commands([$command]);
        $adapter = new FakeCommandAdapter($commands);
        $adapter->configurationStore()->create('global', ['agent' => 'test']);
        $adapter->agentFactoryRegistry()->register('test', static fn (): Agent => $replacement);
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
