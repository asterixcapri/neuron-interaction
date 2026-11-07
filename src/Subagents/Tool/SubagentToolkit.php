<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Tool;

use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Subagents\Subagents;

final class SubagentToolkit extends AbstractToolkit
{
    public function __construct(private readonly Subagents $subagents, private readonly Session $session) {}

    /** @return list<ToolInterface> */
    public function provide(): array
    {
        return $this->subagents->tools($this->session);
    }

    public function guidelines(): string
    {
        return 'Subagents execute independently of the parent turn. '
            . 'Once the requested tasks have been dispatched, end the turn when there is no other work; this does not cancel the tasks or abandon the request. '
            . 'The application delivers completed results in later turns. '
            . 'Do not wait by polling subagent_status. Use status for an explicit progress query or to retrieve an existing result, not to keep a turn alive.';
    }
}
