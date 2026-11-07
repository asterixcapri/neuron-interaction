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
        return (new Registry())->register('researcher', 'Analyze supplied data and explain conclusions', static fn(): Agent
            => (new Agent())->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'))
                ->setInstructions('Analyze the supplied task and data. Explain assumptions and limitations. Do not claim web or filesystem access.'));
    }
}
