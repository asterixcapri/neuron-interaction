<?php

declare(strict_types=1);

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronInteraction\Conversation;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteractionDemo\AIProviderFactory;
use NeuronInteractionDemo\DemoAgent;
use Symfony\Component\Dotenv\Dotenv;

use function NeuronInteractionDemo\execTurn;
use function NeuronInteractionDemo\showMessages;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$storage = new InMemoryStorage();
$stopSignal = new StopSignal($storage, 'demo-response');
$httpClient = new StoppableHttpClient(new AmpHttpClient(), $stopSignal->stopCallback());
$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano', $httpClient));
$conversation = new Conversation($agent, new SessionStore($storage, 'demo-user'), stopSignal: $stopSignal);
$conversation->session()->setTitle('An interrupted answer');

echo '=== Request a long answer, then stop it ===' . \PHP_EOL;
echo 'You: Count from 1 to 1000, separated by commas.' . \PHP_EOL . 'Agent: ';
foreach ($conversation->submitMessage(new UserMessage('Count from 1 to 1000, separated by commas.')) as $event) {
    if ($event instanceof TextChunk) {
        echo $event->content;
        \flush();
        if (!$conversation->responseStopRequested()) {
            // Simulate the user pressing Stop after receiving the first text chunk.
            $conversation->requestInterruption();
        }
    }
}
echo \PHP_EOL . 'Response stopped: ' . ($conversation->responseWasStopped() ? 'yes' : 'no') . \PHP_EOL;

showMessages($conversation->session());

echo \PHP_EOL . '=== Continue after the interrupted response ===' . \PHP_EOL;
execTurn($conversation, 'Say hello in one short sentence.');
