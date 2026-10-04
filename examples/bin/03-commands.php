<?php

declare(strict_types=1);

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteractionDemo\AIProviderFactory;
use NeuronInteractionDemo\DemoAgent;
use NeuronInteractionDemo\ExplainCommand;
use Symfony\Component\Dotenv\Dotenv;

use function NeuronInteractionDemo\showMessages;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$storage = new FileStorage(\dirname(__DIR__) . '/.storage/commands');
$sessionStore = new SessionStore($storage, 'demo-user');

$session = $sessionStore->create();
$conversation = new Conversation($agent, $session, commands: new Commands(
    new HelpCommand(),
    new ClearCommand($sessionStore),
    new ResumeCommand($sessionStore),
    new ExplainCommand(),
));

$session = $conversation->session();
$session->setTitle('Meeting Ada');

execInput($conversation, 'My name is Ada. Acknowledge in one short sentence.');

echo '=== /help lists the registered Commands ===' . \PHP_EOL;
execInput($conversation, '/help');

echo \PHP_EOL . '=== A custom Command prompts the Agent ===' . \PHP_EOL;
execInput($conversation, '/explain PHP generators');

echo '=== /clear starts an empty Session ===' . \PHP_EOL;
execInput($conversation, '/clear');

showMessages($conversation->session());

echo '=== /resume returns to the original Session ===' . \PHP_EOL;
execInput($conversation, '/resume ' . $session->getKey());

showMessages($conversation->session());

execInput($conversation, 'What is my name? Answer with just the name.');

function execInput(Conversation $conversation, string $input): void
{
    echo 'Input: ' . $input . \PHP_EOL;
    $stream = $conversation->sendInput($input);
    foreach ($stream as $event) {
        if ($event instanceof TextChunk) {
            echo $event->content;
            \flush();
        } elseif ($event instanceof Notification) {
            echo $event->text . \PHP_EOL;
        }
    }
    echo \PHP_EOL . \PHP_EOL;
}
