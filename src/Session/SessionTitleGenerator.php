<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use UnexpectedValueException;

use function filter_var;
use function implode;
use function json_encode;
use function trim;
use function ucfirst;

use const FILTER_VALIDATE_INT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/** Generates and persists a topic title within a per-Session attempt limit, preserving messages. */
final readonly class SessionTitleGenerator
{
    public function __construct(
        private AIProviderInterface $provider,
        private Session $session,
        private int $maxAttempts = 3,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('The title generation attempt limit must be positive.');
        }
    }

    public function generate(): ?string
    {
        if ($this->session->getTitle() !== null) {
            return null;
        }

        $attempts = filter_var(
            $this->session->getMetadata()['titleGenerationAttempts'] ?? '0',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        if ($attempts === false || $attempts >= $this->maxAttempts) {
            return null;
        }

        $conversation = $this->conversation($this->session);

        if ($conversation === []) {
            return null;
        }

        $this->session->setMetadata('titleGenerationAttempts', (string) ($attempts + 1));

        $agent = (new SessionTitleAgent())->setThreadId('title-' . $this->session->getKey());
        $agent->setAiProvider($this->provider);
        $result = $agent->structured(
            new UserMessage(json_encode($conversation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            SessionTitleResult::class,
        );
        if (!$result instanceof SessionTitleResult) {
            throw new UnexpectedValueException('The title agent returned an invalid result.');
        }

        $title = trim($result->title ?? '');

        if ($title === '' || $this->session->getTitle() !== null) {
            return null;
        }

        $this->session->setTitle($title);

        return $title;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function conversation(Session $session): array
    {
        $conversation = [];
        foreach ($session->getMessages() as $message) {
            if ($message instanceof ToolCallMessage || $message instanceof ToolResultMessage
                || (!$message instanceof UserMessage && !$message instanceof AssistantMessage)) {
                continue;
            }

            $parts = [];
            foreach ($message->getContentBlocks() as $block) {
                $text = match (true) {
                    $block instanceof ReasoningContent => null,
                    $block instanceof TextContent => $block->getContent(),
                    $block instanceof FileContent && $block->filename !== null => '[File: ' . $block->filename . ']',
                    default => '[' . ucfirst($block->getType()->value) . ']',
                };
                if ($text !== null && $text !== '') {
                    $parts[] = $text;
                }
            }
            if ($parts !== []) {
                $conversation[] = ['role' => $message->getRole(), 'content' => implode("\n\n", $parts)];
            }
        }

        return $conversation;
    }
}
