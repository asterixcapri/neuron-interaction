<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents;

use UnexpectedValueException;

use function array_is_list;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;

final readonly class Run
{
    /** @param list<string> $messages */
    public function __construct(
        public string $id,
        public string $userId,
        public string $sessionKey,
        public string $agent,
        public string $task,
        public string $context = '',
        public string $status = 'queued',
        public ?string $result = null,
        public ?string $error = null,
        public array $messages = [],
        public bool $acknowledged = false,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromRecord(string $id, array $data): self
    {
        foreach (['userId', 'sessionKey', 'agent', 'task', 'context', 'status'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new UnexpectedValueException('Invalid subagent field: ' . $field);
            }
        }
        if (!in_array($data['status'], ['queued', 'running', 'completed', 'failed'], true)) {
            throw new UnexpectedValueException('Invalid subagent status.');
        }
        $result = $data['result'] ?? null;
        $error = $data['error'] ?? null;
        $messages = $data['messages'] ?? null;
        $acknowledged = $data['acknowledged'] ?? null;
        if (($result !== null && !is_string($result)) || ($error !== null && !is_string($error))
            || !is_array($messages) || !array_is_list($messages) || !is_bool($acknowledged)) {
            throw new UnexpectedValueException('Invalid subagent record.');
        }
        $validated = [];
        foreach ($messages as $message) {
            if (!is_string($message)) {
                throw new UnexpectedValueException('Invalid subagent message.');
            }
            $validated[] = $message;
        }
        return new self(
            $id,
            $data['userId'],
            $data['sessionKey'],
            $data['agent'],
            $data['task'],
            $data['context'],
            $data['status'],
            $result,
            $error,
            $validated,
            $acknowledged
        );
    }

    /** @return array{run_id: string, agent: string, status: string, result: ?string, error: ?string} */
    public function summary(): array
    {
        return ['run_id' => $this->id, 'agent' => $this->agent, 'status' => $this->status,
            'result' => $this->result, 'error' => $this->error];
    }
}
