<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;

/**
 * Optional Conversation delegation for frontend Command Adapters.
 *
 * @template-covariant TOutput
 * @implements CommandAdapterInterface<TOutput>
 */
abstract class AbstractCommandAdapter implements CommandAdapterInterface
{
    public function __construct(protected readonly Conversation $conversation) {}

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
}
