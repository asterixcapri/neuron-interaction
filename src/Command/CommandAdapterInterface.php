<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;

/**
 * Presentation-independent operations and invocation lifecycle supplied by an Adapter.
 *
 * @template-covariant TOutput
 */
interface CommandAdapterInterface
{
    /** Refusal returns control to the caller without dispatch or completion. */
    public function admit(CommandInterface $command): bool;

    /** @return TOutput */
    public function afterExecution(CommandExecution $execution): mixed;

    public function say(string $text): void;

    public function warn(string $text): void;

    /** Submit a prompt to the Adapter's Agent flow without receiving its answer. */
    public function promptAgent(string $prompt): void;

    /** Request a later invocation with the chosen value, then return immediately. */
    public function requestSelection(SelectionRequest $request): void;

    public function agent(): Agent;

    public function useAgent(Agent $agent): void;

    public function useSession(Session $session): void;

    public function commands(): Commands;

    public function sessionStore(): SessionStore;

    public function stop(): void;
}
