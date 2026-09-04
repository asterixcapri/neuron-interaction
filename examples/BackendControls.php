<?php

declare(strict_types=1);

namespace NeuronInteraction\Examples;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandControlsInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Session\Sessions;

/** Example request-scoped Adapter; the Host Application supplies Agent execution. */
final class BackendControls implements CommandControlsInterface
{
    /** @var list<string> */
    public array $notices = [];

    /** @var list<string> */
    public array $warnings = [];

    public ?SelectionRequest $selection = null;

    public bool $stopped = false;

    /** @param Closure(Agent, string): void $submitPrompt */
    public function __construct(
        private Agent $answeringAgent,
        private readonly Commands $mountedCommands,
        private readonly Sessions $storedSessions,
        private readonly Closure $submitPrompt,
    ) {
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

    public function commands(): Commands
    {
        return $this->mountedCommands;
    }

    public function sessions(): Sessions
    {
        return $this->storedSessions;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
