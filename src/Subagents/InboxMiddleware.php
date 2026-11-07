<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents;

use NeuronAI\Agent\{AgentResources, AgentState};
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Workflow\Events\Event;

use function array_keys;
use function array_map;
use function count;
use function implode;

/** One cursor per execution attempt. Retries replay the inbox into fresh history. */
final class InboxMiddleware extends AgentMiddleware
{
    private int $cursor = 0;
    private bool $invoked = false;

    public function __construct(private readonly SubagentStore $store, private readonly string $runId) {}

    public function wasInvoked(): bool
    {
        return $this->invoked;
    }

    public function consumed(): int
    {
        return $this->cursor;
    }

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        $this->invoked = true;
        $messages = $this->store->get($this->runId)->messages;
        if ($messages !== []) {
            // Context is attached to the turn's user message by Neuron. This
            // preserves tool-call/result pairing and role alternation in history.
            $state->request->context['subagents_inbox'] = new TextContent(
                "Additional instructions from the parent agent, in arrival order:\n"
                . implode("\n\n", array_map(
                    static fn(int $index, string $message) => '[' . ($index + 1) . '] ' . $message,
                    array_keys($messages),
                    $messages
                ))
            );
        }
        $this->cursor = count($messages);
    }
}
