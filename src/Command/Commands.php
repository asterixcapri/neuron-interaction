<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use InvalidArgumentException;
use Throwable;

/** Mounted Commands in order, with the first matching identifier winning. */
final class Commands
{
    /** @var list<CommandInterface> */
    private array $commands = [];

    /**
     * @param CommandInterface|CommandKitInterface<CommandInterface>|array<array-key, mixed> $commands
     */
    public function __construct(CommandInterface|CommandKitInterface|array $commands = [])
    {
        $this->addCommand($commands);
    }

    /**
     * Mount Commands before running the Adapter. Mutates this collection.
     *
     * @param CommandInterface|CommandKitInterface<CommandInterface>|array<array-key, mixed> $commands
     */
    public function addCommand(CommandInterface|CommandKitInterface|array $commands): self
    {

        foreach (is_array($commands) ? $commands : [$commands] as $command) {
            $members = $command instanceof CommandKitInterface
                ? $command->commands()
                : [$command];

            foreach ($members as $member) {
                $this->commands[] = self::requireCommand($member);
            }
        }

        return $this;
    }

    private static function requireCommand(mixed $command): CommandInterface
    {
        if (!$command instanceof CommandInterface) {
            throw new InvalidArgumentException('A mounted Command must implement CommandInterface.');
        }

        if (!str_starts_with($command->name(), '/')) {
            throw new InvalidArgumentException('A mounted Command identifier must start with a slash.');
        }

        return $command;
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

    public function run(
        string $identifier,
        CommandArguments $arguments,
        CommandControlsInterface $controls,
    ): CommandExecution {
        $command = $this->named($identifier);

        if ($command === null) {
            return CommandExecution::unknown($identifier);
        }

        try {
            $command->run($controls, $arguments);

            return CommandExecution::completed($identifier);
        } catch (Throwable $exception) {
            return CommandExecution::failed($identifier, $exception);
        }
    }
}
