<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;


/**
 * Starts a new Session, leaving the previous one where it is stored.
 *
 * A Host Application mounts it under `clear` or a name of its own.
 *
 * Starting a Session returns the empty History the Agent needs together with
 * its key, and nothing here ever deletes what the new Session replaced.
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

    /** @param CommandControlsAdapterInterface<mixed> $controls */
    public function run(CommandControlsAdapterInterface $controls, CommandArguments $arguments): void
    {
        $agent = $controls->createAgent();
        $agent->setChatHistory($controls->sessionStore()->create());
        $controls->useAgent($agent);
    }
}
