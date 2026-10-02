<?php

declare(strict_types=1);

use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use NeuronInteractionDemo\DemoAgent;
use NeuronInteractionDemo\TerminalCommandAdapter;
use Symfony\Component\Dotenv\Dotenv;

use function NeuronInteractionDemo\execTurn;
use function NeuronInteractionDemo\showMessages;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$sessionStore = new SessionStore(new InMemoryStorage(), 'demo-user');
$conversation = new Conversation($agent, $sessionStore);
$conversation->session()->setTitle('Trip to Lisbon');
execTurn($conversation, 'My destination is Lisbon. Acknowledge in one short sentence.');

$kyoto = $sessionStore->create();
$kyoto->setTitle('Trip to Kyoto');
$conversation->useSession($kyoto);
execTurn($conversation, 'My destination is Kyoto. Acknowledge in one short sentence.');

$commands = (new Commands())->addCommand(new ResumeCommand());
$adapter = new TerminalCommandAdapter($conversation, $commands);

echo '=== /resume requests a Selection ===' . \PHP_EOL;
$commands->run('/resume', '', $adapter);
$selection = $adapter->selection ?? throw new RuntimeException('No Selection was requested.');

// The Host presents the options and sends the chosen value back to the Command.
echo 'Choose a Session number (Enter to cancel): ';
$input = \fgets(\STDIN);
if ($input === false || \trim($input) === '') {
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
$commands->run($selection->command, $option->value, $adapter);

showMessages($conversation->session());
execTurn($conversation, 'What is my destination? Answer with just the city name.');
