<?php

declare(strict_types=1);

namespace NeuronInteraction\Examples;

use Closure;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandControlsAdapterInterface;
use NeuronInteraction\Command\CommandExecution;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Session\SessionStore;

/**
 * Example request-scoped Adapter; the Host Application supplies Agent execution.
 *
 * @phpstan-type BackendResponse array{
 *     identifier: string,
 *     status: string,
 *     error: ?string,
 *     notices: list<string>,
 *     warnings: list<string>,
 *     selection: ?SelectionRequest,
 *     stopped: bool,
 * }
 * @implements CommandControlsAdapterInterface<BackendResponse>
 */
final class BackendAdapter implements CommandControlsAdapterInterface
{
    /** @var list<string> */
    private array $notices = [];

    /** @var list<string> */
    private array $warnings = [];

    private ?SelectionRequest $selection = null;

    private bool $stopped = false;

    /** @param Closure(Agent, string): void $submitPrompt */
    public function __construct(
        private Agent $answeringAgent,
        private readonly Commands $mountedCommands,
        private readonly SessionStore $sessionStore,
        private readonly Closure $submitPrompt,
        private readonly AgentFactoryRegistry $agentFactoryRegistry = new AgentFactoryRegistry(),
        private readonly ConfigurationStore $configurationStore = new ConfigurationStore(new InMemoryStorage(), 'backend'),
    ) {
    }

    public function admit(CommandInterface $command): bool
    {
        return true;
    }

    /** @return BackendResponse */
    public function afterExecution(CommandExecution $execution): array
    {
        return [
            'identifier' => $execution->identifier,
            'status' => $execution->status,
            'error' => $execution->exception?->getMessage(),
            'notices' => $this->notices,
            'warnings' => $this->warnings,
            'selection' => $this->selection,
            'stopped' => $this->stopped,
        ];
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
        ($this->submitPrompt)($this->answeringAgent, $prompt);
    }

    public function requestSelection(SelectionRequest $request): void
    {
        $this->selection = $request;
    }

    public function agent(): Agent
    {
        return $this->answeringAgent;
    }

    public function useAgent(Agent $agent): void
    {
        $this->answeringAgent = $agent;
    }

    public function commands(): Commands
    {
        return $this->mountedCommands;
    }

    public function sessionStore(): SessionStore
    {
        return $this->sessionStore;
    }

    public function agentFactoryRegistry(): AgentFactoryRegistry
    {
        return $this->agentFactoryRegistry;
    }

    public function configurationStore(): ConfigurationStore
    {
        return $this->configurationStore;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
