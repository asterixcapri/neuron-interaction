<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Command\CommandControlsAdapterInterface;
use NeuronInteraction\Command\CommandExecution;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

/** @implements CommandControlsAdapterInterface<CommandExecution> */
class FakeCommandAdapter implements CommandControlsAdapterInterface
{
    /** @var list<string> */
    public array $notices = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $prompts = [];

    /** @var list<SelectionRequest> */
    public array $selections = [];

    public bool $stopped = false;

    public function __construct(
        public Commands $mounted = new Commands(),
        private Agent $answering = new Agent(),
        private SessionStore $collection = new SessionStore(new InMemoryStorage(), 'local-user'),
        private AgentFactoryRegistry $registry = new AgentFactoryRegistry(),
        private ConfigurationStore $configurations = new ConfigurationStore(new InMemoryStorage(), 'local-user'),
    ) {
    }

    public function admit(CommandInterface $command): bool
    {
        return true;
    }

    public function afterExecution(CommandExecution $execution): CommandExecution
    {
        return $execution;
    }

    public function say(string $text): void
    {
        $this->notices[] = $text;
    }

    public function warn(string $text): void
    {
        $this->warnings[] = $text;
    }

    public function promptAgent(string $prompt): void
    {
        $this->prompts[] = $prompt;
    }

    public function requestSelection(SelectionRequest $request): void
    {
        $this->selections[] = $request;
    }

    public function agent(): Agent
    {
        return $this->answering;
    }

    public function useAgent(Agent $agent): void
    {
        $this->answering = $agent;
    }

    public function commands(): Commands
    {
        return $this->mounted;
    }

    public function sessionStore(): SessionStore
    {
        return $this->collection;
    }

    public function agentFactoryRegistry(): AgentFactoryRegistry
    {
        return $this->registry;
    }

    public function configurationStore(): ConfigurationStore
    {
        return $this->configurations;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
