<?php

declare(strict_types=1);

use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteractionDemo\AIProviderFactory;
use NeuronInteractionDemo\DemoAgent;
use NeuronInteractionDemo\ExplainCommand;
use NeuronInteractionDemo\TerminalCommandAdapter;
use Symfony\Component\Dotenv\Dotenv;

use function NeuronInteractionDemo\execTurn;
use function NeuronInteractionDemo\showMessages;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new FileStorage(\dirname(__DIR__) . '/.storage/commands');
$sessionStore = new SessionStore($storage, 'demo-user');
$conversation = new Conversation($agent, $sessionStore);
$session = $conversation->session();
$session->setTitle('Meeting Ada');
execTurn($conversation, 'My name is Ada. Acknowledge in one short sentence.');

$commands = (new Commands())->addCommand([
    new HelpCommand(),
    new ClearCommand(),
    new ResumeCommand(),
    new ExplainCommand(),
]);
$adapter = new TerminalCommandAdapter($conversation, $commands);

echo '=== /help lists the mounted Commands ===' . \PHP_EOL;
$commands->run('/help', '', $adapter);

echo \PHP_EOL . '=== A custom Command prompts the Agent ===' . \PHP_EOL;
$commands->run('/explain', 'PHP generators', $adapter);

echo '=== /clear starts an empty Session ===' . \PHP_EOL;
$commands->run('/clear', '', $adapter);
showMessages($conversation->session());

echo '=== /resume returns to the original Session ===' . \PHP_EOL;
$commands->run('/resume', $session->getKey(), $adapter);
showMessages($conversation->session());
execTurn($conversation, 'What is my name? Answer with just the name.');
