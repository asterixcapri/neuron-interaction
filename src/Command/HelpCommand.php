<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/** Lists the mounted Commands through the Adapter controls. */
final readonly class HelpCommand implements CommandInterface
{
    /**
     * @param string $name the name it answers to, slash omitted
     */
    public function __construct(private string $name = 'help')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Lists what can be typed here.';
    }

    public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
    {
        foreach ($controls->commands()->all() as $command) {
            $controls->say('/' . $command->name() . ' — ' . $command->describe());
        }
    }
}
