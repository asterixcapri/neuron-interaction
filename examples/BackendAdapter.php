<?php

declare(strict_types=1);

namespace NeuronInteraction\Examples;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandExecution;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Selection;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

/**
 * Example request-scoped Adapter; the Host Application supplies Agent execution.
 *
 * @phpstan-type BackendResponse array{
 *     identifier: string,
 *     status: string,
 *     error: ?string,
 *     notices: list<string>,
 *     warnings: list<string>,
 *     errors: list<string>,
 *     selection: ?Selection,
 *     stopped: bool,
 * }
 * @implements CommandAdapterInterface<BackendResponse>
 */
final class BackendAdapter implements CommandAdapterInterface
{
    /** @var list<string> */
    private array $notices = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $errors = [];

    private ?Selection $selection = null;

    private bool $stopped = false;

    /** @param Closure(Agent, string): void $submitPrompt */
    public function __construct(
        private Agent $answeringAgent,
        private readonly Commands $mountedCommands,
        private readonly SessionStore $sessionStore,
        private readonly Closure $submitPrompt,
        private readonly ConfigurationStore $configurationStore = new ConfigurationStore(new InMemoryStorage(), 'local'),
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
            'errors' => $this->errors,
            'selection' => $this->selection,
            'stopped' => $this->stopped,
        ];
    }

    public function notify(string $text): void
    {
        $this->notices[] = $text;
    }

    public function warn(string $text): void
    {
        $this->warnings[] = $text;
    }

    public function error(string $text): void
    {
        $this->errors[] = $text;
    }

    public function promptAgent(string $prompt): void
    {
        ($this->submitPrompt)($this->answeringAgent, $prompt);
    }

    public function requestSelection(Selection $request): void
    {
        $this->selection = $request;
    }

    public function agent(): Agent
    {
        return $this->answeringAgent;
    }

    public function useAgent(Agent $agent): void
    {
        $agent->setChatHistory($this->answeringAgent->getChatHistory());
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

    public function configurationStore(): ConfigurationStore
    {
        return $this->configurationStore;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
