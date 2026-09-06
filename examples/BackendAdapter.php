<?php

declare(strict_types=1);

namespace NeuronInteraction\Examples;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandExecution;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Session\Session;
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
 * @implements CommandAdapterInterface<BackendResponse>
 */
final class BackendAdapter implements CommandAdapterInterface
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
        $agent->setChatHistory($this->answeringAgent->getChatHistory());
        $this->answeringAgent = $agent;
    }

    public function useSession(Session $session): void
    {
        $this->answeringAgent->setChatHistory($session);
    }

    public function commands(): Commands
    {
        return $this->mountedCommands;
    }

    public function sessionStore(): SessionStore
    {
        return $this->sessionStore;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
