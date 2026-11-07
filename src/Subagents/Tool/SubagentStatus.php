<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Tool;

use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};
use NeuronInteraction\Session\Session;
use NeuronInteraction\Subagents\Subagents;

use function json_encode;

use const JSON_THROW_ON_ERROR;

final class SubagentStatus extends Tool
{
    protected string $name = 'subagent_status';
    protected ?string $description = 'Inspect a specific task when the user asks for progress or you need an existing result. Returns its current status and any completed result immediately; it does not wait. Results are delivered automatically, so do not call this to wait for pending tasks. If queued or running, continue other work or end the turn.';

    public function __construct(private readonly Subagents $subagents, private readonly Session $session) {}

    protected function properties(): array
    {
        return [new ToolProperty('run_id', PropertyType::STRING, 'ID returned by spawn_subagent.', true)];
    }

    public function __invoke(string $run_id): string
    {
        return json_encode($this->subagents->status($this->session, $run_id)->summary(), JSON_THROW_ON_ERROR);
    }
}
