<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;

/** State access and ordered requests for one Command invocation. */
final class CommandContext
{
    /** @param Closure(object): void $registerRequest */
    public function __construct(private readonly Conversation $conversation, private readonly Closure $registerRequest) {}

    public function agent(): Agent
    {
        return $this->conversation->agent();
    }

    public function useAgent(Agent $agent): void
    {
        $this->conversation->useAgent($agent);
    }

    public function session(): Session
    {
        return $this->conversation->session();
    }

    public function useSession(Session $session): void
    {
        $this->conversation->useSession($session);
    }

    public function sessionStore(): SessionStore
    {
        return $this->conversation->sessionStore();
    }

    public function configurationStore(): ConfigurationStore
    {
        return $this->conversation->configurationStore();
    }

    /** @return list<CommandInterface> */
    public function commands(): array
    {
        return $this->conversation->commands()->all();
    }

    public function notify(string $text, NotificationLevel $level = NotificationLevel::Info): void
    {
        ($this->registerRequest)(new Notification($text, $level));
    }
}
