<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/** A named operation whose effects use the supplied context. */
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

    /** Raw argument text is interpreted by the Command itself. */
    public function run(CommandContext $context, string $value): void;
}
