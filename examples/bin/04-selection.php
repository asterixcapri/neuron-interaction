<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\ExitRequest;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

// A Command can request a choice and handle its value on a later invocation.
final class LanguageCommand implements CommandInterface
{
    public function name(): string
    {
        return '/language';
    }

    public function describe(): string
    {
        return 'Choose the language used by the Agent.';
    }

    public function run(CommandContext $context, string $value): void
    {
        if ($value === '') {
            $context->requestSelection(new SelectionRequest(
                $this->name(),
                'Choose a response language',
                [
                    new SelectionOption('Italian', 'Italiano'),
                    new SelectionOption('English', 'English'),
                    new SelectionOption('Spanish', 'Español'),
                ],
            ));

            return;
        }

        if (!\in_array($value, ['Italian', 'English', 'Spanish'], true)) {
            $context->notify('Unknown language.', NotificationLevel::Error);

            return;
        }

        // This demo Agent uses only these instructions; setInstructions replaces them.
        $context->agent()->setInstructions(
            'Always reply in ' . $value . ', even when the user writes in another language.',
        );
        $context->notify('Response language: ' . $value);
    }
}

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');
$session = $sessionStore->create();

$conversation = new Conversation($agent, $session);

$conversation->setCommands(new Commands(
    new LanguageCommand(),
    new ExitCommand(),
));

echo 'Enter a message or use /language to choose a response language.' . \PHP_EOL;
echo 'Try /language Italian or /exit.' . \PHP_EOL . \PHP_EOL;

$input = null;

while (true) {
    if ($input === null) {
        echo '> ';
        $input = \fgets(\STDIN);
        if ($input === false) {
            return;
        }
        $input = \trim($input);
        if ($input === '') {
            $input = null;
            continue;
        }
    }

    $stream = $conversation->sendInput($input);
    $input = null;

    echo \PHP_EOL;

    foreach ($stream as $event) {
        if ($event instanceof TextChunk) {
            echo $event->content;
        } elseif ($event instanceof Notification) {
            echo $event->text . \PHP_EOL;
        } elseif ($event instanceof SelectionRequest) {
            echo $event->prompt . \PHP_EOL;
            foreach ($event->options as $index => $option) {
                echo ($index + 1) . '. ' . $option->label . \PHP_EOL;
            }

            echo 'Choose an option number (Enter to cancel): ';
            $choice = \intval(\fgets(\STDIN));
            if ($choice === 0) {
                echo 'Selection cancelled.' . \PHP_EOL;
                continue;
            }

            $option = $event->options[$choice - 1] ?? null;
            if ($option === null) {
                echo 'Invalid option number.' . \PHP_EOL;
                continue;
            }

            // Send the choice on the next iteration, after consuming this stream.
            $input = new CommandInput($event->command, $option->value);
        } elseif ($event instanceof ExitRequest) {
            echo 'Host exit requested.' . \PHP_EOL;
            return;
        }
    }

    echo \PHP_EOL . \PHP_EOL;
}
