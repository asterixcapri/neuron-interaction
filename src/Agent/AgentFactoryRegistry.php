<?php

declare(strict_types=1);

namespace NeuronInteraction\Agent;

use Closure;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Configuration\Configuration;

/** Application-owned construction; factories must return fresh, unstarted Agents. */
final class AgentFactoryRegistry
{
    /** @var array<string, Closure(Configuration): Agent> */
    private array $factories = [];

    /** @param Closure(Configuration): Agent $factory */
    public function register(string $identifier, Closure $factory): void
    {
        if (trim($identifier) === '') {
            throw new InvalidArgumentException('Agent factory identifier must be a non-empty string.');
        }
        if (isset($this->factories[$identifier])) {
            throw new InvalidArgumentException('Agent factory already registered: ' . $identifier);
        }

        $this->factories[$identifier] = $factory;
    }

    public function create(Configuration $configuration): Agent
    {
        $identifier = $configuration->get('agent');
        if (!is_string($identifier) || trim($identifier) === '') {
            throw new InvalidArgumentException('Configuration agent must be a non-empty factory identifier.');
        }
        $factory = $this->factories[$identifier]
            ?? throw new InvalidArgumentException('Unknown Agent factory: ' . $identifier);

        return $factory(new Configuration(
            $configuration->getKey(),
            $configuration->getUserId(),
            $configuration->all(),
        ));
    }
}
