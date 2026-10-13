<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\ExitRequest;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Event\SelectionRequest;
use NeuronInteraction\Event\SessionChanged;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');
$session = $sessionStore->create();

$conversation = new Conversation($agent, $session);

$conversation->setCommands(new Commands(
    new ResumeCommand($sessionStore),
    new ClearCommand($sessionStore),
    new ExitCommand(),
));

echo 'Enter a message or use /resume, /clear or /exit.' . \PHP_EOL;
echo 'Try a message, /clear, then /resume to choose the earlier Session.' . \PHP_EOL . \PHP_EOL;

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
        } elseif ($event instanceof SessionChanged) {
            echo 'Session changed: ' . $event->session->getKey() . \PHP_EOL;
            foreach ($conversation->getDisplayMessages() as $message) {
                echo \ucfirst($message->getRole()) . ': ' . $message->getContent() . \PHP_EOL;
            }
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
