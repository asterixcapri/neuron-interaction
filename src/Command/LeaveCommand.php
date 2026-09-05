<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/** Requests that the Adapter stop its interaction. */
final readonly class LeaveCommand implements CommandInterface
{
    /**
     * @param string $name the name it answers to, including the leading slash
     */
    public function __construct(private string $name = '/exit')
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

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
    {
        $adapter->stop();
    }
}
