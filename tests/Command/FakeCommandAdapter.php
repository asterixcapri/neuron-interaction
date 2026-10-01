<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandExecution;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Selection;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

/** @implements CommandAdapterInterface<CommandExecution> */
class FakeCommandAdapter implements CommandAdapterInterface
{
    private Session $session;

    /** @var list<string> */
    public array $notices = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<UserMessage> */
    public array $prompts = [];

    /** @var list<Selection> */
    public array $selections = [];

    public bool $stopped = false;

    public function __construct(
        public Commands $mounted = new Commands(),
        private Agent $answering = new Agent(),
        private SessionStore $collection = new SessionStore(new InMemoryStorage(), 'local-user'),
        private ConfigurationStore $configurations = new ConfigurationStore(new InMemoryStorage(), 'local-user'),
        ?Session $session = null,
    ) {
        if ($session === null && $this->answering->getThreadId() !== null && $this->answering->getChatHistory()->getMessages() !== []) {
            throw new InvalidArgumentException('An Agent with existing messages requires an explicit Session.');
        }
        $this->useSession($session ?? $this->collection->create());
    }

    public function admit(CommandInterface $command): bool
    {
        return true;
    }

    public function afterExecution(CommandExecution $execution): CommandExecution
    {
        return $execution;
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

    public function promptAgent(UserMessage $prompt): void
    {
        $this->prompts[] = $prompt;
    }

    public function requestSelection(Selection $request): void
    {
        $this->selections[] = $request;
    }

    public function agent(): Agent
    {
        return $this->answering;
    }

    public function useAgent(Agent $agent): void
    {
        $this->answering = $this->session->bindTo($agent);
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function useSession(Session $session): void
    {
        $selected = $this->collection->read($session->getKey());
        if ($selected === null) {
            throw new InvalidArgumentException('The selected Session does not belong to this SessionStore.');
        }
        $agent = $selected->bindTo($this->answering);
        $this->session = $selected;
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

    public function configurationStore(): ConfigurationStore
    {
        return $this->configurations;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
