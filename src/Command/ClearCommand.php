<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/**
 * Starts a new Session, leaving the previous one where it is stored.
 *
 * A Host Application mounts it under `clear` or a name of its own.
 *
 * Starting a Session returns the empty History the Agent needs. Minting its
 * key stays behind the Sessions seam, and nothing here ever deletes what the
 * new Session replaced.
 */
final readonly class ClearCommand implements CommandInterface
{
    /** @param string $name the presentation-neutral identifier */
    public function __construct(private string $name = '/clear')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Starts a new Session, leaving the current one stored.';
    }

    public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
    {
        $controls->agent()->setChatHistory($controls->sessions()->start());
    }
}
