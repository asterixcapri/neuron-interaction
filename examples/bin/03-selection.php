<?php

declare(strict_types=1);

use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Command\SessionChanged;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use NeuronInteractionDemo\DemoAgent;
use Symfony\Component\Dotenv\Dotenv;

use function NeuronInteractionDemo\execTurn;
use function NeuronInteractionDemo\showMessages;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');

$conversation = new Conversation($agent, $sessionStore, commands: new Commands(new ResumeCommand()));

$session = $conversation->session();
$session->setTitle('Trip to Lisbon');

execTurn($conversation, 'My destination is Lisbon. Acknowledge in one short sentence.');

$kyoto = $sessionStore->create();
$kyoto->setTitle('Trip to Kyoto');

$conversation->useSession($kyoto);

execTurn($conversation, 'My destination is Kyoto. Acknowledge in one short sentence.');

echo '=== /resume requests a SelectionRequest ===' . \PHP_EOL;
$selection = null;
foreach ($conversation->submitInput('/resume') as $event) {
    if ($event instanceof SelectionRequest) {
        $selection = $event;
        echo $event->prompt . \PHP_EOL;
        foreach ($event->options as $index => $option) {
            echo ($index + 1) . '. ' . $option->label . ' — ' . $option->description
                . ' (' . $event->command . ' ' . $option->value . ')' . \PHP_EOL;
        }
    }
}
$selection ??= throw new RuntimeException('No SelectionRequest was requested.');

// The Host presents the options and sends the chosen value back to the Command.
echo 'Choose a Session number (Enter to cancel): ';
$input = \fgets(\STDIN);
if ($input === false || \trim($input) === '') {
    // Cancellation closes the host picker without submitting any input.
    echo 'Selection cancelled.' . \PHP_EOL;
    exit(0);
}
$number = \filter_var(\trim($input), \FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => \count($selection->options)],
]);
if ($number === false) {
    echo 'Invalid Session number.' . \PHP_EOL;
    exit(1);
}
$option = $selection->options[$number - 1];
foreach ($conversation->submitInput(new CommandInput($selection->command, $option->value)) as $event) {
    if ($event instanceof SessionChanged) {
        echo 'Selected Session: ' . $event->session->getTitle() . \PHP_EOL;
    }
}

showMessages($conversation->session());
execTurn($conversation, 'What is my destination? Answer with just the city name.');
