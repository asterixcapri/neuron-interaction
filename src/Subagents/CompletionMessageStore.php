<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents;

use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\{Message, ToolCallMessage, ToolResultMessage};

use function array_keys;
use function is_array;
use function is_string;
use function json_decode;

/** Record the event identity in the SAME write as the final assistant message.
 * This closes the crash window between saving the answer and acknowledging the inbox.
 * @internal
 */
final class CompletionMessageStore implements MessageStoreInterface
{
    public bool $recording = true;

    public const EVENT_KEY = 'subagent_completion_id';
    public const READ_RESULTS_KEY = 'subagent_read_result_ids';

    /** @var array<string, true> */
    private array $readResults = [];

    public function __construct(private MessageStoreInterface $store, private ?string $eventId = null) {}

    public function loadActive(string $threadId): array
    {
        return $this->store->loadActive($threadId);
    }
    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        return $this->store->loadAll($threadId, $limit, $before);
    }
    public function append(string $threadId, Message $message): void
    {
        if ($this->recording && $message instanceof ToolResultMessage) {
            foreach ($message->getToolCalls() as $call) {
                if ($call->getName() !== 'subagent_status' || !is_string($result = $call->getResult())) {
                    continue;
                }
                $result = json_decode($result, true);
                if (is_array($result) && ($result['status'] ?? null) === 'completed'
                    && is_string($id = $result['run_id'] ?? null) && $id !== $this->eventId) {
                    $this->readResults[$id] = true;
                }
            }
        }
        if ($this->recording && $message->getRole() === 'assistant' && !$message instanceof ToolCallMessage) {
            if ($this->eventId !== null) {
                $message->addMetadata(self::EVENT_KEY, $this->eventId);
            }
            if ($this->readResults !== []) {
                $message->addMetadata(self::READ_RESULTS_KEY, array_keys($this->readResults));
            }
        }
        $this->store->append($threadId, $message);
    }
    public function archive(string $threadId, int $count): void
    {
        $this->store->archive($threadId, $count);
    }
    public function clear(string $threadId): void
    {
        $this->store->clear($threadId);
    }
}
