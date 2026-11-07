<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents;

use Closure;
use InvalidArgumentException;
use LogicException;
use NeuronInteraction\Execution\ExecutionLock;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Storage\StorageInterface;
use OutOfBoundsException;
use Symfony\Component\Lock\LockFactory;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function json_encode;
use function trim;

use const JSON_THROW_ON_ERROR;

/** A completed, unacknowledged run is itself the durable completion event. */
final readonly class SubagentStore
{
    private const string NAMESPACE = 'subagent-runs';

    public function __construct(private StorageInterface $storage, public LockFactory $locks) {}

    public function create(Session $session, string $agent, string $task): Run
    {
        $session->assertExists();
        if (trim($task) === '' || trim($agent) === '') {
            throw new InvalidArgumentException('Agent and task must not be empty.');
        }
        $id = $this->storage->create(self::NAMESPACE, [
            'userId' => $session->getUserId(), 'sessionKey' => $session->getKey(),
            'agent' => $agent, 'task' => $task, 'status' => 'queued',
            'result' => null, 'error' => null, 'messages' => [], 'acknowledged' => false,
        ]);
        return $this->get($id);
    }

    /** Trusted worker lookup. User-facing callers must use forSession(). */
    public function get(string $id): Run
    {
        $data = $this->storage->get(self::NAMESPACE, $id);
        if ($data === null) {
            throw new OutOfBoundsException('Unknown subagent run.');
        }
        return Run::fromRecord($id, $data);
    }

    public function forSession(string $id, Session $session): Run
    {
        $run = $this->get($id);
        if ($run->userId !== $session->getUserId() || $run->sessionKey !== $session->getKey()) {
            throw new OutOfBoundsException('Unknown subagent run.');
        }
        return $run;
    }

    /** @return list<Run> */
    public function pending(Session $session): array
    {
        return array_values(array_filter($this->pendingRuns(), static fn(Run $run): bool
            => $run->userId === $session->getUserId() && $run->sessionKey === $session->getKey()));
    }

    /**
     * Trusted relay lookup.
     * @return list<array{userId: string, sessionKey: string}>
     */
    public function pendingSessions(): array
    {
        $sessions = [];
        foreach ($this->pendingRuns() as $run) {
            $key = json_encode([$run->userId, $run->sessionKey], JSON_THROW_ON_ERROR);
            $sessions[$key] = ['userId' => $run->userId, 'sessionKey' => $run->sessionKey];
        }
        return array_values($sessions);
    }

    /** @return list<Run> */
    private function pendingRuns(): array
    {
        $pending = [];
        foreach ($this->storage->list(self::NAMESPACE) as $entry) {
            $run = Run::fromRecord($entry['key'], $entry['value']);
            if ($run->status === 'completed' && !$run->acknowledged) {
                $pending[] = $run;
            }
        }
        return $pending;
    }

    public function setStatus(string $id, string $status, ?string $error = null): void
    {
        if (!in_array($status, ['running', 'failed'], true)) {
            throw new InvalidArgumentException('Unsupported status transition.');
        }
        $this->mutate($id, function () use ($id, $status, $error): void {
            if ($this->get($id)->status === 'completed') {
                throw new LogicException('A completed run cannot be restarted.');
            }
            $this->update($id, ['status' => $status, 'error' => $error]);
        });
    }

    public function appendMessage(string $id, Session $session, string $message): int
    {
        if (trim($message) === '') {
            throw new InvalidArgumentException('Message cannot be empty.');
        }
        return $this->mutate($id, function () use ($id, $session, $message): int {
            $run = $this->forSession($id, $session);
            if (!in_array($run->status, ['queued', 'running'], true)) {
                throw new LogicException('The run no longer accepts messages.');
            }
            $messages = [...$run->messages, $message];
            $this->update($id, ['messages' => $messages]);
            return count($messages);
        });
    }

    public function completeIfConsumed(string $id, int $consumed, string $result): ?Run
    {
        return $this->mutate($id, function () use ($id, $consumed, $result): ?Run {
            $run = $this->get($id);
            if ($run->status !== 'running') {
                throw new LogicException('Only a running subagent can complete.');
            }
            if (count($run->messages) !== $consumed) {
                return null;
            }
            $this->update($id, ['status' => 'completed', 'result' => $result, 'error' => null]);
            return $this->get($id);
        });
    }

    public function acknowledge(string $id, Session $session): void
    {
        $this->mutate($id, function () use ($id, $session): void {
            if ($this->forSession($id, $session)->status !== 'completed') {
                throw new LogicException('Only a completed run can be acknowledged.');
            }
            $this->update($id, ['acknowledged' => true]);
        });
    }

    /** @param array<string, mixed> $fields */
    private function update(string $id, array $fields): void
    {
        $assignments = [];
        foreach ($fields as $name => $value) {
            $assignments[] = ['path' => [$name], 'value' => $value];
        }
        if (!$this->storage->update(self::NAMESPACE, $id, $assignments)) {
            throw new OutOfBoundsException('The run no longer exists.');
        }
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function mutate(string $id, Closure $operation): mixed
    {
        $lock = ExecutionLock::acquire($this->locks, 'run-state.' . $id, blocking: true);
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
