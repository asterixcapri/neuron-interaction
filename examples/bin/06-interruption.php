<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronInteraction\Conversation;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');

$stopSignal = new StopSignal($storage, 'demo-response');
$httpClient = new StoppableHttpClient(new AmpHttpClient(), $stopSignal->stopCallback());

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano', $httpClient));

$session = $sessionStore->create();
$session->setTitle('An interrupted answer');

$conversation = new Conversation($agent, $session, stopSignal: $stopSignal);

echo '=== Request a long answer, then stop it ===' . \PHP_EOL;
$input = 'Write 1000 words on London Docklands.';
$stopAfter = \random_int(40, 50);
$textChunks = 0;

echo 'You: ' . $input . \PHP_EOL;
echo 'Stop after ' . $stopAfter . ' text chunks.' . \PHP_EOL . 'Agent: ';

$stream = $conversation->sendInput($input);

foreach ($stream as $event) {
    if ($event instanceof TextChunk) {
        echo $event->content;
        \flush();
        if (++$textChunks === $stopAfter) {
            // Simulate the user pressing Stop after a random number of text chunks.
            $conversation->requestInterruption();
        }
    }
}

echo \PHP_EOL . 'Response stopped: ' . ($conversation->responseWasStopped() ? 'yes' : 'no') . \PHP_EOL;
