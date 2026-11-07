<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents;

use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Execution\ExecutionLock;
use RuntimeException;
use Throwable;

use function is_string;

final readonly class Executor
{
    public function __construct(private Registry $registry, private SubagentStore $store) {}

    public function execute(string $id): Run
    {
        $lock = ExecutionLock::acquire($this->store->locks, 'run.' . $id);
        try {
            $run = $this->store->get($id);
            if ($run->status === 'completed') {
                return $run;
            }
            $this->store->setStatus($id, 'running');
            try {
                $agent = $this->registry->create($run->agent);
                $agent->setMessageStore(new InMemoryMessageStore());
                $agent->setThreadId('subagent-' . $run->id);
                $inbox = new InboxMiddleware($this->store, $run->id);
                // A factory preserves this cursor: Neuron otherwise clones middleware.
                $agent->addMiddleware(InferenceNode::class, static fn() => $inbox);
                $prompt = $run->task;
                if ($run->context !== '') {
                    $prompt .= "\n\nContext supplied by the parent agent:\n" . $run->context;
                }
                $messages = [new UserMessage($prompt)];
                do {
                    $state = $agent->chat($messages);
                    if ($state->isInterrupted()) {
                        throw new RuntimeException('Subagent interrupted; interactive approval/resume is not supported yet.');
                    }
                    $content = $state->getMessage()?->getContent();
                    if (!is_string($content)) {
                        throw new RuntimeException('Subagent did not return a text result.');
                    }
                    $finished = $this->store->completeIfConsumed($id, $inbox->consumed(), $content);
                    if ($finished !== null) {
                        return $finished;
                    }
                    if (!$inbox->wasInvoked()) {
                        throw new RuntimeException('Inbox requires the Neuron inference pipeline; custom chat() overrides must run its middleware.');
                    }
                    // A correction arrived during the final inference. Continue on the
                    // same agent/history instead of dropping an accepted message.
                    $messages = [new UserMessage('Review and update your previous answer using the additional instructions from the parent agent.')];
                } while (true);
            } catch (Throwable $error) {
                $this->store->setStatus($id, 'failed', $error->getMessage());
                throw $error; // Messenger retains its retry/failure transport semantics.
            }
        } finally {
            $lock->release();
        }
    }
}
