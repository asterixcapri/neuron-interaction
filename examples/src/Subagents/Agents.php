<?php

declare(strict_types=1);

namespace NeuronInteractionDemo\Subagents;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Subagents\Registry;
use NeuronInteractionDemo\AIProviderFactory;

final class Agents
{
    public static function registry(): Registry
    {
        return (new Registry())->register('general', 'Complete a delegated task using the supplied information', static fn(): Agent
            => (new Agent())->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'))
                ->setInstructions('Complete the supplied task and return a self-contained result. Follow the requested constraints and output format. Explain assumptions, missing information and limitations. Do not claim web or filesystem access.'));
    }
}
