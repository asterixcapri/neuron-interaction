<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/** Lists the mounted Commands through the Adapter. */
final readonly class HelpCommand implements CommandInterface
{
    /**
     * @param string $name the name it answers to, including the leading slash
     */
    public function __construct(private string $name = '/help')
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

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
    {
        foreach ($adapter->commands()->all() as $command) {
            $adapter->say($command->name() . ' — ' . $command->describe());
        }
    }
}
