<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$storage = new FileStorage(\dirname(__DIR__) . '/.storage/preferences');
$configurationStore = new ConfigurationStore($storage, 'demo-user');

$language = 'Spanish';
$configurationStore->write('language', $language);

echo 'Saved language: ' . $configurationStore->read('language') . \PHP_EOL;

$configurationStoreAnotherUser = new ConfigurationStore($storage, 'another-user');
echo "Another user's language: " . $configurationStoreAnotherUser->read('language') . \PHP_EOL;

// Apply the saved preference to the Agent, even when the input is in another language.
$language = $configurationStore->read('language');

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));
$agent->setInstructions("Always respond in {$language}, regardless of the user's language.");

$sessionStorage = new InMemoryStorage();
$sessionStore = new SessionStore($sessionStorage, 'demo-user');
$session = $sessionStore->create();

$conversation = new Conversation($agent, $session);
$conversation->setConfigurationStore($configurationStore);

$input = 'What is a PHP generator? Answer in one short sentence.';
echo \PHP_EOL . 'You: ' . $input . \PHP_EOL;

echo 'Agent: ';

$stream = $conversation->sendInput($input);

foreach ($stream as $event) {
    if ($event instanceof TextChunk) {
        echo $event->content;
        \flush();
    }
}

echo \PHP_EOL;
