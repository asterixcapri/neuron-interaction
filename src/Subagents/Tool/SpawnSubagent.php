<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Tool;

use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};
use NeuronInteraction\Session\Session;
use NeuronInteraction\Subagents\Subagents;

use function array_keys;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class SpawnSubagent extends Tool
{
    protected string $name = 'spawn_subagent';
    protected ?string $description = 'Start a background task and return its run_id immediately. The task continues after you end your turn; ending the turn does not abandon the user\'s request. The application delivers its result in a new turn. After dispatching the needed tasks, end the turn if no other work is available. Do not use subagent_status to wait.';

    public function __construct(private readonly Subagents $subagents, private readonly Session $session) {}

    protected function properties(): array
    {
        return [
            new ToolProperty('agent', PropertyType::STRING, json_encode($this->subagents->catalog(), JSON_THROW_ON_ERROR), true, array_keys($this->subagents->catalog())),
            new ToolProperty('task', PropertyType::STRING, 'Self-contained task including relevant data, constraints and expected deliverable. Parent history is not inherited.', true),
        ];
    }

    public function __invoke(string $agent, string $task): string
    {
        return json_encode($this->subagents->spawn($this->session, $agent, $task)->summary(), JSON_THROW_ON_ERROR);
    }
}
