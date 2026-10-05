<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/** Requests that the Host Application leave its interaction. */
final readonly class ExitCommand implements CommandInterface
{
    /**
     * @param string $name the name it answers to, including the leading slash
     */
    public function __construct(private string $name = '/exit') {}

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Stops the interaction.';
    }

    public function run(CommandContext $context, string $value): void
    {
        $context->requestExit();
    }
}
