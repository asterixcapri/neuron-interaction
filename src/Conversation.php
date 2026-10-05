<?php

declare(strict_types=1);

namespace NeuronInteraction;

use Closure;
use Generator;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Storage\InMemoryStorage;
use Throwable;

use function array_any;
use function is_string;
use function trim;

/** Executes one conversation turn; pending inputs and scheduling belong to the client. */
final class Conversation
{
    private Agent $agent;

    private Session $session;

    private Commands $commands;

    private ConfigurationStore $configurationStore;

    private UserMessageProcessors $userMessageProcessors;

    private ?InputHistory $inputHistory = null;

    private ?StopSignal $stopSignal = null;

    private bool $responseStopRequested = false;

    /** @param (Closure(CommandInterface): bool)|null $admitCommand */
    public function __construct(
        Agent $agent,
        Session $session,
        private readonly ?Closure $admitCommand = null,
    ) {
        $this->commands = new Commands();
        $this->configurationStore = new ConfigurationStore(new InMemoryStorage(), 'local');
        $this->userMessageProcessors = new UserMessageProcessors();

        $this->session = $session;
        $this->agent = $session->bindToAgent($agent);
    }

    public function agent(): Agent
    {
        return $this->agent;
    }

    public function useAgent(Agent $agent): void
    {
        $this->agent = $this->session->bindToAgent($agent);
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function useSession(Session $session): void
    {
        $this->agent = $session->bindToAgent($this->agent);
        $this->session = $session;
    }

    /** @return list<Message> */
    public function getMessages(?int $limit = null, ?string $before = null): array
    {
        return $this->session->getMessages($limit, $before);
    }

    /** @return list<Message> */
    public function getDisplayMessages(?int $limit = null, ?string $before = null): array
    {
        $messages = $this->getMessages($limit, $before);
        foreach ($messages as $index => $message) {
            if ($message instanceof UserMessage) {
                $messages[$index] = $this->userMessageProcessors->forDisplay(clone $message);
            }
        }

        return $messages;
    }

    public function commands(): Commands
    {
        return $this->commands;
    }

    public function setCommands(Commands $commands): void
    {
        $this->commands = $commands;
    }

    public function configurationStore(): ConfigurationStore
    {
        return $this->configurationStore;
    }

    public function setConfigurationStore(ConfigurationStore $configurationStore): void
    {
        $this->configurationStore = $configurationStore;
    }

    public function userMessageProcessors(): UserMessageProcessors
    {
        return $this->userMessageProcessors;
    }

    public function setUserMessageProcessors(UserMessageProcessors $userMessageProcessors): void
    {
        $this->userMessageProcessors = $userMessageProcessors;
    }

    public function inputHistory(): ?InputHistory
    {
        return $this->inputHistory;
    }

    public function setInputHistory(InputHistory $inputHistory): void
    {
        $this->inputHistory = $inputHistory;
    }

    public function stopSignal(): ?StopSignal
    {
        return $this->stopSignal;
    }

    public function setStopSignal(StopSignal $stopSignal): void
    {
        $this->stopSignal = $stopSignal;
    }

    public function supportsResponseStop(): bool
    {
        return $this->stopSignal !== null;
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

    public function responseStopRequested(): bool
    {
        return $this->responseStopRequested;
    }

    public function responseWasStopped(): bool
    {
        return $this->responseStopRequested && $this->stopSignal?->isRequested() === false;
    }

    /**
     * Prepare the submitted message now; consume the returned stream to execute it.
     *
     * @return Generator<int, object, mixed, AgentState|null>
     */
    public function sendInput(string|UserMessage|CommandInput $input): Generator
    {
        if ($this->inputHistory !== null) {
            $original = match (true) {
                is_string($input) => new UserMessage($input),
                $input instanceof CommandInput => new UserMessage(
                    $input->identifier . ($input->value === '' ? '' : ' ' . $input->value),
                ),
                default => $input,
            };
            $this->inputHistory->append($original);
        }

        if (is_string($input)) {
            if (trim($input) === '') {
                return $this->emptyStream();
            }
            $input = CommandInput::parse($input) ?? new UserMessage($input);
        }
        if ($input instanceof CommandInput) {
            return $this->streamCommand($input);
        }
        return $this->prepareMessage($input);
    }

    /** @return Generator<int, object, mixed, AgentState> */
    private function prepareMessage(UserMessage $message): Generator
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
        $lastState = null;
        foreach ($requests as $request) {
            if ($request instanceof UserMessage) {
                $lastState = yield from $this->prepareMessage($request);
            } else {
                yield $request;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $lastState;
    }

    /** @return Generator<int, object, mixed, AgentState> */
    private function streamMessage(UserMessage $message): Generator
    {
        $agent = $this->agent;

        $this->responseStopRequested = false;
        $this->stopSignal?->clear();

        return yield from $agent->stream($message);
    }
}
