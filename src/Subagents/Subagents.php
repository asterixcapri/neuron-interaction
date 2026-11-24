<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents;

use Closure;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\ToolInterface;
use NeuronInteraction\Event\{SubagentCompleted, SubagentDispatched};
use NeuronInteraction\Session\Session;
use NeuronInteraction\Subagents\Tool\{SendSubagentMessage, SpawnSubagent, SubagentStatus};
use Throwable;

final readonly class Subagents
{
    /**
     * The callback runs synchronously in the process publishing the event.
     *
     * @param null|Closure(SubagentDispatched|SubagentCompleted): void $onEvent
     */
    public function __construct(
        private Registry $registry,
        public SubagentStore $store,
        private BackgroundAdapterInterface $adapter,
        private ?Closure $onEvent = null,
    ) {}

    /** @return array<string, string> */
    public function catalog(): array
    {
        return $this->registry->catalog();
    }

    public function spawn(Session $session, string $agent, string $task): Run
    {
        $this->registry->assertExists($agent);
        $run = $this->store->create($session, $agent, $task);
        try {
            $this->adapter->dispatch($run->id);
        } catch (Throwable $error) {
            $this->store->setStatus($run->id, 'failed', $error->getMessage());
            throw $error;
        }
        $this->publish(new SubagentDispatched($run));
        return $run;
    }

    public function acknowledgeCompletion(Session $session, Run $run, Message $reply, bool $recovered = false): void
    {
        $this->store->acknowledge($run->id, $session);
        $this->publish(new SubagentCompleted($run, $reply, recovered: $recovered));
    }

    private function publish(SubagentDispatched|SubagentCompleted $event): void
    {
        if ($this->onEvent !== null) {
            ($this->onEvent)($event);
        }
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
