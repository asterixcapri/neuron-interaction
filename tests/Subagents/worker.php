<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Subagents\Console\WorkCommand;
use NeuronInteraction\Subagents\{Executor, Registry, SubagentStore};
use Symfony\Component\Console\Application;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$directory = \getenv('NEURON_SUBAGENT_TEST_STORAGE');
if ($directory === false) {
    throw new RuntimeException('Missing test storage');
}
$registry = (new Registry())->register('researcher', 'Test child', static fn(): Agent
    => (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('From a separate PHP process'))));
$store = new SubagentStore(new FileStorage($directory), new LockFactory(new SemaphoreStore()));
$application = new Application();
$application->addCommand(new WorkCommand(new Executor($registry, $store)));
$application->run();
