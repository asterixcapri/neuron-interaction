<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/** Lists the registered Commands through their context. */
final readonly class HelpCommand implements CommandInterface
{
    /**
     * @param string $name the name it answers to, including the leading slash
     */
    public function __construct(private string $name = '/help') {}

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Lists what can be typed here.';
    }

    public function run(CommandContext $context, string $value): void
    {
        foreach ($context->commands() as $command) {
            $context->notify($command->name() . ' — ' . $command->describe());
        }
    }
}
