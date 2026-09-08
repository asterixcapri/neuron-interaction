<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Session\SessionStore;

/**
 * Presentation-independent operations and invocation lifecycle supplied by an Adapter.
 *
 * @template-covariant TOutput
 */
interface CommandControlsAdapterInterface
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

    /** The currently active Agent, including its configuration and conversation. */
    public function agent(): Agent;

    /**
     * Construct a fresh Agent of the active Agent's class without activating it.
     * The class must support make() without arguments. Instance configuration
     * applied after construction is not copied.
     */
    public function newAgent(): Agent;

    /** Activate the supplied Agent with its own History and present that History. */
    public function useAgent(Agent $agent): void;

    public function commands(): Commands;

    public function sessionStore(): SessionStore;

    public function agentFactoryRegistry(): AgentFactoryRegistry;

    public function configurationStore(): ConfigurationStore;

    public function stop(): void;
}
