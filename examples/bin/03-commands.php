<?php

declare(strict_types=1);

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Command\AgentChanged;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitRequest;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Command\SessionChanged;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteractionDemo\AIProviderFactory;
use NeuronInteractionDemo\DemoAgent;
use NeuronInteractionDemo\ExplainCommand;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new FileStorage(\dirname(__DIR__) . '/.storage/commands');
$sessionStore = new SessionStore($storage, 'demo-user');

$session = $sessionStore->create();
$session->setTitle('Commands example');

$conversation = new Conversation($agent, $session);

$commands = (new Commands())->addCommand(
    new HelpCommand(),
    new ClearCommand($sessionStore),
    new ResumeCommand($sessionStore),
    new ExplainCommand(),
    new LeaveCommand(),
);

$conversation->setCommands($commands);

echo 'Enter a message or a Command.' . \PHP_EOL;
echo 'Try /help, /explain PHP generators, /clear, /resume or /exit.' . \PHP_EOL . \PHP_EOL;

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
            \flush();
        } elseif ($event instanceof Notification) {
            // Show feedback in a toast, status area or terminal message.
            echo $event->text . \PHP_EOL;
        } elseif ($event instanceof SessionChanged) {
            // The Session has already changed: refresh the client's History.
            echo 'Session changed: ' . $event->session->getKey()
                . ' — ' . ($event->session->getTitle() ?? 'New session') . \PHP_EOL;

            foreach ($event->session->getMessages() as $message) {
                echo \ucfirst($message->getRole()) . ': ' . $message->getContent() . \PHP_EOL;
            }
        } elseif ($event instanceof AgentChanged) {
            // Refresh the Agent's name or model in the UI.
            echo 'Agent changed: ' . $event->agent::class . \PHP_EOL;
        } elseif ($event instanceof SelectionRequest) {
            // The choice becomes a new backend request on the next loop iteration.
            echo $event->prompt . \PHP_EOL;

            foreach ($event->options as $index => $option) {
                echo ($index + 1) . '. ' . $option->label . ' — ' . ($option->description ?? '') . \PHP_EOL;
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

            $input = new CommandInput($event->command, $option->value);
        } elseif ($event instanceof ExitRequest) {
            // The client decides whether to close its input loop or screen.
            echo 'Host exit requested.' . \PHP_EOL;
            return;
        }
    }

    echo \PHP_EOL . \PHP_EOL;
}
