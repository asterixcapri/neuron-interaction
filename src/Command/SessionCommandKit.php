<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

/**
 * The shared Session Commands, mounted in one line.
 *
 * Both commands reach the live Sessions supplied by the Adapter
 * through their Controls.
 *
 * An application in which conversations are not thrown away mounts it without
 * `ClearCommand`; one that only wants to return to an earlier conversation
 * keeps `ResumeCommand` alone. Names are the commands' own business, so a kit
 * is the short way in and writing the two commands out by hand remains the way
 * to rename them.
 *
 * @extends AbstractCommandKit<CommandInterface>
 */
final class SessionCommandKit extends AbstractCommandKit
{
    protected function provide(): array
    {
        return [
            new ClearCommand(),
            new ResumeCommand(),
        ];
    }
}
