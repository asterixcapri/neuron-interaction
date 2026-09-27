<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use UnexpectedValueException;

/** Generates a topic title from a Session without modifying its messages. */
final readonly class SessionTitleGenerator
{
    public function __construct(
        private AIProviderInterface $provider,
        private Session $session,
    ) {
    }

    public function generate(): ?string
    {
        $conversation = $this->conversation($this->session);

        if ($conversation === []) {
            return null;
        }

        $agent = new SessionTitleAgent();
        $agent->setAiProvider($this->provider);
        $result = $agent->structured(
            new UserMessage(json_encode($conversation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            SessionTitleResult::class,
        );
        if (!$result instanceof SessionTitleResult) {
            throw new UnexpectedValueException('The title agent returned an invalid result.');
        }

        $title = trim($result->title ?? '');

        return $title === '' ? null : $title;
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
