<?php

declare(strict_types=1);

namespace NeuronInteraction\Agent;

use NeuronInteraction\Configuration\ConfigurationStore;

interface ConfiguredAgentInterface
{
    /** Create a fresh Agent, ready to receive its conversation History. */
    public static function createAgent(ConfigurationStore $configurationStore): static;
}
