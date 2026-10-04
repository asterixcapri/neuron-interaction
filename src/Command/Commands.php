<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use InvalidArgumentException;

use function array_values;

/** Registry of Commands by name; later registrations replace earlier ones. */
final class Commands
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    public function __construct(CommandInterface ...$commands)
    {
        $this->addCommand(...$commands);
    }

    public function addCommand(CommandInterface ...$commands): self
    {
        foreach ($commands as $command) {
            $name = $command->name();
            if (!CommandInput::isIdentifier($name)) {
                throw new InvalidArgumentException('A Command identifier must be a slash followed by letters, digits, underscores or hyphens.');
            }
            $this->commands[$name] = $command;
        }

        return $this;
    }

    /** @return list<CommandInterface> */
    public function all(): array
    {
        return array_values($this->commands);
    }

    public function named(string $identifier): ?CommandInterface
    {
        return $this->commands[$identifier] ?? null;
    }
}
