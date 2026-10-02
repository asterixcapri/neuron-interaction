<?php

declare(strict_types=1);

namespace NeuronInteraction;

use Generator;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Message\UserMessageProcessorInterface;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;

use function array_any;
use function trim;

/** Executes one conversation turn; pending inputs and scheduling belong to the client. */
final class Conversation
{
    private Agent $agent;

    private Session $session;

    private bool $responseStopRequested = false;

    public function __construct(
        Agent $agent,
        private readonly SessionStore $sessionStore,
        ?Session $session = null,
        private readonly ?StopSignal $stopSignal = null,
        private readonly UserMessageProcessorInterface $userMessageProcessors = new UserMessageProcessors(),
    ) {
        if ($session === null) {
            if ($agent->getThreadId() !== null && $agent->getChatHistory()->getMessages() !== []) {
                throw new InvalidArgumentException('An Agent with existing messages requires an explicit Session.');
            }

            $session = $this->sessionStore->create();
        } else {
            $session = $this->ownedSession($session);
        }

        $this->session = $session;
        $this->agent = $session->bindTo($agent);
    }

    public function sessionStore(): SessionStore
    {
        return $this->sessionStore;
    }

    public function agent(): Agent
    {
        return $this->agent;
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function userMessageProcessors(): UserMessageProcessorInterface
    {
        return $this->userMessageProcessors;
    }

    /**
     * Prepare the submitted message now; consume the returned stream to execute it.
     *
     * @return Generator<int, object, mixed, AgentState>
     */
    public function submitMessage(UserMessage $message): Generator
    {
        $message = $this->userMessageProcessors->forAgent(clone $message);

        $hasText = trim($message->getContent() ?? '') !== '';
        $hasAttachments = array_any(
            $message->getContentBlocks(),
            static fn($block): bool => !$block instanceof TextContent,
        );

        if (!$hasText && !$hasAttachments) {
            throw new InvalidArgumentException('The prepared user message is empty.');
        }

        $message = clone $message;

        return $this->streamMessage($message);
    }

    public function requestInterruption(): bool
    {
        if ($this->stopSignal === null || $this->responseStopRequested) {
            return false;
        }

        $this->stopSignal->request();
        $this->responseStopRequested = true;

        return true;
    }

    public function supportsResponseStop(): bool
    {
        return $this->stopSignal !== null;
    }

    public function responseStopRequested(): bool
    {
        return $this->responseStopRequested;
    }

    public function responseWasStopped(): bool
    {
        return $this->responseStopRequested && $this->stopSignal?->isRequested() === false;
    }

    public function useAgent(Agent $agent): void
    {
        $this->agent = $this->session->bindTo($agent);
    }

    public function useSession(Session $session): void
    {
        $session = $this->ownedSession($session);
        $this->agent = $session->bindTo($this->agent);
        $this->session = $session;
    }

    /** @return Generator<int, object, mixed, AgentState> */
    private function streamMessage(UserMessage $message): Generator
    {
        $agent = $this->agent;
        $this->resetResponseStop();

        return yield from $agent->stream($message);
    }

    private function ownedSession(Session $session): Session
    {
        return $this->sessionStore->read($session->getKey())
            ?? throw new InvalidArgumentException('The selected Session does not belong to this SessionStore.');
    }

    private function resetResponseStop(): void
    {
        $this->responseStopRequested = false;
        $this->stopSignal?->clear();
    }
}
