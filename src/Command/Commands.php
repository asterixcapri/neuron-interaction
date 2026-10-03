<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use InvalidArgumentException;

use function array_values;

/** Immutable registry of uniquely named Commands in registration order. */
final class Commands
{
    /** @var list<CommandInterface> */
    private readonly array $commands;

    public function __construct(CommandInterface ...$commands)
    {
        $names = [];
        foreach ($commands as $command) {
            $name = $command->name();
            if (!CommandInput::isIdentifier($name)) {
                throw new InvalidArgumentException('A Command identifier must be a slash followed by letters, digits, underscores or hyphens.');
            }
            if (isset($names[$name])) {
                throw new InvalidArgumentException('Duplicate Command identifier: ' . $name);
            }
            $names[$name] = true;
        }
        $this->commands = array_values($commands);
    }

    /** @return list<CommandInterface> */
    public function all(): array
    {
        return $this->commands;
    }

    public function named(string $identifier): ?CommandInterface
    {
        foreach ($this->commands as $command) {
            if ($command->name() === $identifier) {
                return $command;
            }
        }
        return null;
    }
}
