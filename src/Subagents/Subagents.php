<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents;

use NeuronAI\Tools\ToolInterface;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Subagents\Tool\{SendSubagentMessage, SpawnSubagent, SubagentStatus};
use Throwable;

final readonly class Subagents
{
    public function __construct(
        private Registry $registry,
        public SubagentStore $store,
        private BackgroundAdapterInterface $adapter,
    ) {}

    /** @return array<string, string> */
    public function catalog(): array
    {
        return $this->registry->catalog();
    }

    public function spawn(Session $session, string $agent, string $task, string $context = ''): Run
    {
        $this->registry->assertExists($agent);
        $run = $this->store->create($session, $agent, $task, $context);
        try {
            $this->adapter->dispatch($run->id);
        } catch (Throwable $error) {
            $this->store->setStatus($run->id, 'failed', $error->getMessage());
            throw $error;
        }
        return $run;
    }

    /** @return array{run_id: string, message_id: int, status: string} */
    public function sendMessage(Session $session, string $id, string $message): array
    {
        return ['run_id' => $id, 'message_id' => $this->store->appendMessage($id, $session, $message), 'status' => 'accepted'];
    }

    public function status(Session $session, string $id): Run
    {
        return $this->store->forSession($id, $session);
    }

    /** @return list<ToolInterface> */
    public function tools(Session $session): array
    {
        return [new SpawnSubagent($this, $session), new SendSubagentMessage($this, $session), new SubagentStatus($this, $session)];
    }
}
