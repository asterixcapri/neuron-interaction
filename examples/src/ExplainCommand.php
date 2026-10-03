<?php

declare(strict_types=1);

namespace NeuronInteractionDemo;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\NotificationLevel;

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

    public function run(CommandContext $context, string $value): void
    {
        if (trim($value) === '') {
            $context->notify('Specify a topic: /explain PHP generators', NotificationLevel::Error);
            return;
        }

        $context->promptAgent(new UserMessage('Explain ' . $value . ' in two short sentences.'));
    }
}
