<?php

declare(strict_types=1);

namespace NeuronInteraction\Examples;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\AbstractCommandAdapter;
use NeuronInteraction\Command\CommandExecution;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Selection;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

/**
 * Example request-scoped Adapter; the Host Application supplies Agent execution.
 *
 * @phpstan-type BackendResponse array{
 *     identifier: string,
 *     status: string,
 *     error: ?string,
 *     notices: list<string>,
 *     warnings: list<string>,
 *     errors: list<string>,
 *     selection: ?Selection,
 *     stopped: bool,
 * }
 * @extends AbstractCommandAdapter<BackendResponse>
 */
final class BackendAdapter extends AbstractCommandAdapter
{
    /** @var list<string> */
    private array $notices = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $errors = [];

    private ?Selection $selection = null;

    private bool $stopped = false;

    /** @param Closure(Agent, UserMessage): void $submitPrompt */
    public function __construct(
        Agent $answeringAgent,
        private readonly Commands $mountedCommands,
        SessionStore $sessionStore,
        private readonly Closure $submitPrompt,
        private readonly ConfigurationStore $configurationStore = new ConfigurationStore(new InMemoryStorage(), 'local'),
        ?Session $session = null,
    ) {
        parent::__construct(new Conversation($answeringAgent, $sessionStore, session: $session));
    }

    public function admit(CommandInterface $command): bool
    {
        return true;
    }

    /** @return BackendResponse */
    public function afterExecution(CommandExecution $execution): array
    {
        return [
            'identifier' => $execution->identifier,
            'status' => $execution->status,
            'error' => $execution->exception?->getMessage(),
            'notices' => $this->notices,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'selection' => $this->selection,
            'stopped' => $this->stopped,
        ];
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
        ($this->submitPrompt)($this->agent(), $prompt);
    }

    public function requestSelection(Selection $request): void
    {
        $this->selection = $request;
    }

    public function commands(): Commands
    {
        return $this->mountedCommands;
    }

    public function configurationStore(): ConfigurationStore
    {
        return $this->configurationStore;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
