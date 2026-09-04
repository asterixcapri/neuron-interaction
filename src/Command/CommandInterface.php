<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/** A named operation whose effects use the active Adapter's Command controls. */
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

    public function run(CommandControlsInterface $controls, CommandArguments $arguments): void;
}
