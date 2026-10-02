<?php

declare(strict_types=1);

namespace NeuronInteractionDemo;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;

use function trim;

final class ExplainCommand implements CommandInterface
{
    public function name(): string
    {
        return '/explain';
    }

    public function describe(): string
    {
        return 'Ask the Agent to explain a topic in two short sentences.';
    }

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function run(CommandAdapterInterface $adapter, string $value): void
    {
        if (trim($value) === '') {
            $adapter->error('Specify a topic: /explain PHP generators');
            return;
        }

        $adapter->promptAgent(new UserMessage('Explain ' . $value . ' in two short sentences.'));
    }
}
