<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Tool;

use NeuronAI\Tools\{PropertyType, Tool, ToolProperty};
use NeuronInteraction\Session\Session;
use NeuronInteraction\Subagents\Subagents;

use function json_encode;

use const JSON_THROW_ON_ERROR;

final class SendSubagentMessage extends Tool
{
    protected string $name = 'send_subagent_message';
    protected ?string $description = 'Send additional instructions to a queued or running subagent without waiting. Acceptance does not mean the model has already read them. The subagent reads messages before its next inference; a call already in progress cannot be changed. Finished subagents reject messages.';

    public function __construct(private readonly Subagents $subagents, private readonly Session $session) {}

    protected function properties(): array
    {
        return [
            new ToolProperty('run_id', PropertyType::STRING, 'ID returned by spawn_subagent.', true),
            new ToolProperty('message', PropertyType::STRING, 'Correction, clarification or additional instructions.', true),
        ];
    }

    public function __invoke(string $run_id, string $message): string
    {
        return json_encode($this->subagents->sendMessage($this->session, $run_id, $message), JSON_THROW_ON_ERROR);
    }
}
