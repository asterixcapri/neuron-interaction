<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\ExitRequest;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

final class LanguageCommand implements CommandInterface
{
    public function name(): string
    {
        return '/language';
    }

    public function describe(): string
    {
        return 'Set the response language: /language English, /language Italian, /language Spanish or /language Portuguese.';
    }

    public function run(CommandContext $context, string $value): void
    {
        if (!\in_array($value, ['English', 'Italian', 'Spanish', 'Portuguese'], true)) {
            $context->notify('Use /language English, /language Italian, /language Spanish or /language Portuguese.', NotificationLevel::Error);
            return;
        }

        $context->configurationStore()->write('language', $value);
        $context->agent()->setInstructions("Always respond in {$value}, regardless of the user's language.");
        $context->notify('Response language saved: ' . $value);
    }
}

$storage = new FileStorage(\dirname(__DIR__) . '/.storage/custom-command');
$configurationStore = new ConfigurationStore($storage, 'demo-user');
$language = $configurationStore->read('language', 'English');

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));
$agent->setInstructions("Always respond in {$language}, regardless of the user's language.");

$sessionStorage = new InMemoryStorage();
$sessionStore = new SessionStore($sessionStorage, 'demo-user');
$session = $sessionStore->create();

$conversation = new Conversation($agent, $session);
$conversation->setConfigurationStore($configurationStore);
$conversation->setCommands(new Commands(new LanguageCommand(), new ExitCommand()));

echo 'Current response language: ' . $language . \PHP_EOL;
echo 'Try /language Italian, then ask a question in English. Use /exit to leave.' . \PHP_EOL . \PHP_EOL;

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
            echo $event->text . \PHP_EOL;
        } elseif ($event instanceof ExitRequest) {
            return;
        }
    }

    echo \PHP_EOL . \PHP_EOL;
}
