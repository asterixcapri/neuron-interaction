<?php

declare(strict_types=1);

namespace NeuronInteraction\Message;

use NeuronAI\Chat\Messages\UserMessage;

/** Preparation and display projection of complete user messages. */
interface UserMessageProcessorInterface
{
    /**
     * Prepare a submitted message before it is sent to the Agent.
     * This includes prompts produced by Commands; leave already expanded content intact.
     * Throw to reject submission; the caller decides how to report the error.
     * Return a new message without modifying the input or its content blocks.
     */
    public function forAgent(UserMessage $message): UserMessage;

    /**
     * Project a complete message for display without changing saved History.
     * Accept unknown content unchanged; do not modify the input or its blocks.
     * Repeated calls with the same original message must produce the same view.
     * This projection need not be an inverse of forAgent().
     */
    public function forDisplay(UserMessage $message): UserMessage;
}
