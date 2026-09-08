<?php

declare(strict_types=1);

namespace NeuronInteraction\Agent;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Configuration\ConfigurationStore;
use ReflectionClass;

/** Application-owned construction; registered classes create fresh, unstarted Agents. */
final class AgentFactoryRegistry
{
    /** @var array<string, class-string<Agent&ConfiguredAgentInterface>> */
    private array $agentClasses = [];

    public function register(string $identifier, string $agentClass): void
    {
        if (trim($identifier) === '') {
            throw new InvalidArgumentException('Agent factory identifier must be a non-empty string.');
        }
        if (isset($this->agentClasses[$identifier])) {
            throw new InvalidArgumentException('Agent factory already registered: ' . $identifier);
        }
        if (!is_a($agentClass, Agent::class, true)
            || !is_a($agentClass, ConfiguredAgentInterface::class, true)
            || (new ReflectionClass($agentClass))->isAbstract()) {
            throw new InvalidArgumentException('Registered class must be a concrete Agent implementing ConfiguredAgentInterface: ' . $agentClass);
        }

        $this->agentClasses[$identifier] = $agentClass;
    }

    public function create(string $identifier, ConfigurationStore $configurationStore): Agent
    {
        $agentClass = $this->agentClasses[$identifier]
            ?? throw new InvalidArgumentException('Unknown Agent factory: ' . $identifier);

        return $agentClass::createAgent($configurationStore);
    }
}
