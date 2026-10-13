<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\AgentChanged;
use NeuronInteraction\Event\ExitRequest;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Event\SelectionRequest;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

// A Command can offer choices and receive the chosen value on a later turn.
final class ModelCommand implements CommandInterface
{
    private const array MODELS = ['openai:gpt-5-nano', 'openai:gpt-5-mini'];

    public function name(): string
    {
        return '/model';
    }

    public function describe(): string
    {
        return 'Choose the Agent model.';
    }

    public function run(CommandContext $context, string $value): void
    {
        if ($value === '') {
            $context->requestSelection(new SelectionRequest(
                $this->name(),
                'Choose a model',
                [
                    new SelectionOption(self::MODELS[0], 'GPT-5 nano'),
                    new SelectionOption(self::MODELS[1], 'GPT-5 mini'),
                ],
            ));

            return;
        }

        if (!\in_array($value, self::MODELS, true)) {
            $context->notify('Unknown model.', NotificationLevel::Error);
            return;
        }

        $provider = AIProviderFactory::create($value);
        $context->useAgent($context->agent()->setAiProvider($provider));
        $context->notify('Model changed to ' . $value . '.');
    }
}

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5-nano'));

$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');
$session = $sessionStore->create();

$conversation = new Conversation($agent, $session);

$conversation->setCommands(new Commands(
    new ModelCommand(),
    new ExitCommand(),
));

echo 'Enter a message or use /model to choose the Agent model.' . \PHP_EOL;
echo 'Try /model, then send a message, or use /exit.' . \PHP_EOL . \PHP_EOL;

$input = null;

while (true) {
    if ($input === null) {
        echo '> ';
        $line = \fgets(\STDIN);
        if ($line === false) {
            return;
        }
        $input = \trim($line);
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
        } elseif ($event instanceof AgentChanged) {
            echo 'Agent changed: ' . $event->agent::class . \PHP_EOL;
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
