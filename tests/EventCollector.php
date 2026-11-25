<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests;

use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Conversation;

final class EventCollector
{
    /** @return list<object> */
    public static function input(Conversation $conversation, string|UserMessage|CommandInput $input, ?AgentState &$state = null): array
    {
        $events = [];
        $state = $conversation->runInput($input, static function (object $event) use (&$events): void {
            $events[] = $event;
        });
        return $events;
    }

    /** @return list<object> */
    public static function completions(Conversation $conversation, ?AgentState &$state = null): array
    {
        $events = [];
        $state = $conversation->runCompletions(static function (object $event) use (&$events): void {
            $events[] = $event;
        });
        return $events;
    }
}
