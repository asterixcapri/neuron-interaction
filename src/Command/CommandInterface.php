<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/** A named operation whose effects use the active Adapter. */
interface CommandInterface
{
    /**
     * The slash-prefixed identifier it answers to: `/review`.
     */
    public function name(): string;

    /**
     * One line, for a listing of what can be typed here.
     */
    public function describe(): string;

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void;
}
