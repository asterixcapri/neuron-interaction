<?php

declare(strict_types=1);

namespace NeuronInteraction;

use Closure;
use Generator;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Message\UserMessageProcessorInterface;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use Throwable;

use function array_any;
use function is_string;
use function trim;

/** Executes one conversation turn; pending inputs and scheduling belong to the client. */
final class Conversation
{
    private readonly ConfigurationStore $configurationStore;

    private Agent $agent;

    private Session $session;

    private bool $responseStopRequested = false;

    /** @param (Closure(CommandInterface): bool)|null $admitCommand */
    public function __construct(
        Agent $agent,
        private readonly SessionStore $sessionStore,
        ?Session $session = null,
        private readonly ?StopSignal $stopSignal = null,
        private readonly UserMessageProcessorInterface $userMessageProcessors = new UserMessageProcessors(),
        private readonly Commands $commands = new Commands(),
        ?ConfigurationStore $configurationStore = null,
        private readonly ?Closure $admitCommand = null,
    ) {
        $this->configurationStore = $configurationStore ?? new ConfigurationStore(new InMemoryStorage(), 'local');

        if ($session === null) {
            if ($agent->getThreadId() !== null && $agent->getChatHistory()->getMessages() !== []) {
                throw new InvalidArgumentException('An Agent with existing messages requires an explicit Session.');
            }

            $session = $this->sessionStore->create();
        } else {
            $session = $this->ownedSession($session);
        }

        $this->session = $session;
        $this->agent = $session->bindToAgent($agent);
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

    public function commands(): Commands
    {
        return $this->commands;
    }

    public function configurationStore(): ConfigurationStore
    {
        return $this->configurationStore;
    }

    public function userMessageProcessors(): UserMessageProcessorInterface
    {
        return $this->userMessageProcessors;
    }

    /**
     * Prepare the submitted message now; consume the returned stream to execute it.
     *
     * @return Generator<int, object, mixed, AgentState|null>
     */
    public function submitInput(string|UserMessage|CommandInput $input): Generator
    {
        if (is_string($input)) {
            if (trim($input) === '') {
                return $this->emptyStream();
            }
            $input = CommandInput::parse($input) ?? new UserMessage($input);
        }
        if ($input instanceof CommandInput) {
            return $this->streamCommand($input);
        }
        $message = $input;
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

    /** @return Generator<int, object, mixed, null> */
    private function emptyStream(): Generator
    {
        yield from [];
        return null;
    }

    /** @return Generator<int, object, mixed, AgentState|null> */
    private function streamCommand(CommandInput $input): Generator
    {
        $command = $this->commands->named($input->identifier);
        if ($command === null) {
            yield new Notification('Unknown Command: ' . $input->identifier, NotificationLevel::Error);
            return null;
        }
        if ($this->admitCommand !== null && !($this->admitCommand)($command)) {
            yield new Notification(
                $input->identifier . ' is refused by the Host Application.',
                NotificationLevel::Warning,
            );
            return null;
        }
        $requests = [];
        $context = new CommandContext($this, static function (object $request) use (&$requests): void {
            $requests[] = $request;
        });
        $failure = null;
        try {
            $command->run($context, $input->value);
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        foreach ($requests as $request) {
            yield $request;
        }
        if ($failure !== null) {
            throw $failure;
        }
        return null;
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
        $this->agent = $this->session->bindToAgent($agent);
    }

    public function useSession(Session $session): void
    {
        $session = $this->ownedSession($session);
        $this->agent = $session->bindToAgent($this->agent);
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
        return $this->sessionStore->get($session->getKey())
            ?? throw new InvalidArgumentException('The selected Session does not belong to this SessionStore.');
    }

    private function resetResponseStop(): void
    {
        $this->responseStopRequested = false;
        $this->stopSignal?->clear();
    }
}
