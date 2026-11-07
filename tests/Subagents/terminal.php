<?php

declare(strict_types=1);

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';
require \dirname(__DIR__, 2) . '/examples/vendor/autoload.php';

$testProvider = new class (new AssistantMessage('FIRST_ANSWER'), new AssistantMessage('SECOND_ANSWER')) extends FakeAIProvider {
    /** @return Generator<int, StreamChunk, mixed, ProviderResponse> */
    public function stream(Message ...$messages): Generator
    {
        if (!\function_exists('Amp\\delay')) {
            throw new RuntimeException('Install the example dependencies.');
        }
        echo "TURN_STARTED\n";
        Amp\delay(0.5);
        return yield from parent::stream(...$messages);
    }
};

if (!isset($argv[1])) {
    throw new RuntimeException('Pass the isolated example path.');
}
$example = $argv[1];
$argv = [$example];
require $example;
