<?php

declare(strict_types=1);

namespace NeuronInteractionDemo;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\AbstractCommandAdapter;
use NeuronInteraction\Command\CommandExecution;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Selection;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
use NeuronInteraction\Storage\InMemoryStorage;

use function flush;

use const PHP_EOL;

/** @extends AbstractCommandAdapter<CommandExecution> */
final class TerminalCommandAdapter extends AbstractCommandAdapter
{
    public ?Selection $selection = null;

    public function __construct(
        Conversation $conversation,
        private readonly Commands $mountedCommands,
        private readonly ConfigurationStore $settings = new ConfigurationStore(new InMemoryStorage(), 'demo-user'),
    ) {
        parent::__construct($conversation);
    }

    public function admit(CommandInterface $command): bool
    {
        return true;
    }

    public function afterExecution(CommandExecution $execution): CommandExecution
    {
        if ($execution->exception !== null) {
            $this->error($execution->exception->getMessage());
        } elseif ($execution->status === 'unknown') {
            $this->error('Unknown command: ' . $execution->identifier);
        }

        return $execution;
    }

    public function notify(string $text): void
    {
        echo $text . PHP_EOL;
    }

    public function warn(string $text): void
    {
        echo 'Warning: ' . $text . PHP_EOL;
    }

    public function error(string $text): void
    {
        echo 'Error: ' . $text . PHP_EOL;
    }

    public function promptAgent(UserMessage $prompt): void
    {
        echo 'You: ' . $prompt->getContent() . PHP_EOL . 'Agent: ';
        foreach ($this->conversation->submitMessage($prompt) as $event) {
            if ($event instanceof TextChunk) {
                echo $event->content;
                flush();
            }
        }
        echo PHP_EOL . PHP_EOL;
    }

    public function requestSelection(Selection $request): void
    {
        $this->selection = $request;
        echo $request->prompt . PHP_EOL;
        foreach ($request->options as $index => $option) {
            echo ($index + 1) . '. ' . $option->label . PHP_EOL;
        }
    }

    public function commands(): Commands
    {
        return $this->mountedCommands;
    }

    public function configurationStore(): ConfigurationStore
    {
        return $this->settings;
    }

    public function stop(): void
    {
        echo 'Interaction ended.' . PHP_EOL;
    }
}
