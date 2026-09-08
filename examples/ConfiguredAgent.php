<?php

declare(strict_types=1);

namespace NeuronInteraction\Examples;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Agent\ConfiguredAgentInterface;
use NeuronInteraction\Configuration\ConfigurationStore;
use RuntimeException;

/** A local example Agent that runs without credentials or network access. */
final class ConfiguredAgent extends Agent implements ConfiguredAgentInterface
{
    private string $model = 'local';
    private string $capability = 'search';

    public static function createAgent(ConfigurationStore $configurationStore): static
    {
        $configuration = $configurationStore->read('agent')
            ?? throw new RuntimeException('Agent configuration "agent" is missing.');
        $model = $configuration->get('model');
        $capability = $configuration->get('capability');
        if (!is_string($model) || !is_string($capability)) {
            throw new InvalidArgumentException('Model and capability must be strings.');
        }
        $agent = new static();
        $agent->model = $model;
        $agent->capability = $capability;

        return $agent;
    }

    protected function provider(): AIProviderInterface
    {
        return new FakeAIProvider(new AssistantMessage($this->model . ':' . $this->capability));
    }
}
