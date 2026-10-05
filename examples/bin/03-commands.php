<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\ExitRequest;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\SessionChanged;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteractionDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new FileStorage(\dirname(__DIR__) . '/.storage/commands');
$sessionStore = new SessionStore($storage, 'demo-user');

$session = $sessionStore->create();
$session->setTitle('Commands example');

$conversation = new Conversation($agent, $session);

$conversation->setCommands(new Commands(
    new HelpCommand(),
    new ClearCommand($sessionStore),
    new ExitCommand(),
));

echo 'Enter a message or a Command.' . \PHP_EOL;
echo 'Try /help, /clear or /exit.' . \PHP_EOL . \PHP_EOL;

while (true) {
    echo '> ';
    $line = \fgets(\STDIN);
    if ($line === false) {
        return;
    }
    $input = \trim($line);
    if ($input === '') {
        continue;
    }

    $stream = $conversation->sendInput($input);

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
        } elseif ($event instanceof ExitRequest) {
            // The client decides whether to close its input loop or screen.
            echo 'Host exit requested.' . \PHP_EOL;
            return;
        }
    }

    echo \PHP_EOL . \PHP_EOL;
}
