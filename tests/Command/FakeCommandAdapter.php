<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandExecution;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

/** @implements CommandAdapterInterface<CommandExecution> */
class FakeCommandAdapter implements CommandAdapterInterface
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
        $agent->setChatHistory($this->answering->getChatHistory());
        $this->answering = $agent;
    }

    public function useSession(Session $session): void
    {
        $this->answering->setChatHistory($session);
    }

    public function commands(): Commands
    {
        return $this->mounted;
    }

    public function sessions(): SessionStore
    {
        return $this->collection;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
