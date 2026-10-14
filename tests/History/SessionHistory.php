<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\History;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Session\Session;

use function trim;

/** Opens Neuron's context to seed or clear a stored test conversation. */
final class SessionHistory
{
    public static function append(Session|ChatHistory $session, Message $message): void
    {
        self::of($session)->addMessage($message);
        if ($session instanceof Session) {
            self::recordFixture($session, $message);
        }
    }

    public static function recordFixture(Session $session, Message $message): void
    {
        if (!$message instanceof UserMessage || $message instanceof ToolResultMessage) {
            return;
        }
        if (trim($message->getContent() ?? '') !== '') {
            $session->registerUse();
            return;
        }
        foreach ($message->getContentBlocks() as $block) {
            if (!$block instanceof TextContent) {
                $session->registerUse();
                return;
            }
        }
    }

    public static function clear(Session $session): void
    {
        self::of($session)->flushAll();
        $session->resetContent();
    }

    public static function of(Session|ChatHistory $session): ChatHistory
    {
        return $session instanceof ChatHistory ? $session : $session->bindToAgent(new Agent())->getChatHistory();
    }
}
