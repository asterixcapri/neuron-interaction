<?php

declare(strict_types=1);

namespace NeuronInteractionDemo;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;

use function flush;
use function ucfirst;

use const PHP_EOL;

function execTurn(Conversation $conversation, string $message): void
{
    echo 'You: ' . $message . PHP_EOL;
    echo 'Agent: ';

    $handler = $conversation->submitMessage(new UserMessage($message));

    foreach ($handler as $event) {
        if ($event instanceof TextChunk) {
            echo $event->content;
            flush();
        }
    }

    echo PHP_EOL . PHP_EOL;
}

function showSessions(SessionStore $sessionStore): void
{
    echo '=== Saved Sessions ===' . PHP_EOL;

    foreach ($sessionStore->list() as $summary) {
        echo $summary->getKey() . ' — ' . $summary->getTitle() . PHP_EOL;
    }

    echo PHP_EOL;
}

function showMessages(Session $session): void
{
    echo '=== ' . $session->getTitle() . ' ===' . PHP_EOL . PHP_EOL;

    foreach ($session->getMessages() as $message) {
        echo ucfirst($message->getRole()) . ': ' . $message->getContent() . PHP_EOL;
    }
}
