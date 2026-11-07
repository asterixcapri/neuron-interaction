<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents;

use Closure;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;

use function array_map;
use function trim;

final class Registry
{
    /** @var array<string, array{description: string, factory: Closure(): Agent}> */
    private array $definitions = [];

    /**
     * Factory must return a NEW agent.
     * @param Closure(): Agent $factory
     */
    public function register(string $name, string $description, Closure $factory): self
    {
        if (trim($name) === '' || isset($this->definitions[$name])) {
            throw new InvalidArgumentException('Empty or duplicate agent name.');
        }
        $this->definitions[$name] = ['description' => $description, 'factory' => $factory];
        return $this;
    }

    /** @return array<string, string> */
    public function catalog(): array
    {
        return array_map(static fn($definition) => $definition['description'], $this->definitions);
    }

    public function assertExists(string $name): void
    {
        if (!isset($this->definitions[$name])) {
            throw new InvalidArgumentException('Unknown subagent: ' . $name);
        }
    }

    public function create(string $name): Agent
    {
        $this->assertExists($name);
        return ($this->definitions[$name]['factory'])();
    }
}
