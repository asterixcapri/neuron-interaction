<?php

declare(strict_types=1);

namespace NeuronInteraction\Examples;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;

/** Application-owned construction: dependencies stay live, settings stay serializable. */
final class ConfiguredAgent extends Agent
{
    private string $model = 'local';
    private string $capability = 'search';

    /** @param Closure(string, string): AIProviderInterface $providerFactory */
    public function __construct(private readonly Closure $providerFactory)
    {
        parent::__construct();
    }

    public function configure(string $model, string $capability): self
    {
        $this->model = $model;
        $this->capability = $capability;

        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        return ($this->providerFactory)($this->model, $this->capability);
    }
}
