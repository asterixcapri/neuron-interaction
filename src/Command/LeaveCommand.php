<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/** Requests that the Adapter stop its interaction. */
final readonly class LeaveCommand implements CommandInterface
{
    /**
     * @param string $name the name it answers to, slash omitted
     */
    public function __construct(private string $name = 'exit')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Stops the interaction.';
    }

    public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
    {
        $controls->stop();
    }
}
