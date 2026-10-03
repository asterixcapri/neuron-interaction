<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

require_once __DIR__ . '/../../vendor/autoload.php';

$echo = new class implements CommandInterface {
    public function name(): string
    {
        return '/echo';
    }

    public function describe(): string
    {
        return 'Show the supplied text';
    }

    public function run(CommandContext $context, string $value): void
    {
        $context->notify($value);
    }
};

$conversation = new Conversation(
    new Agent(),
    new SessionStore(new InMemoryStorage(), 'demo-user'),
    commands: new Commands($echo),
);

foreach ($conversation->submitInput('/echo Hello from Conversation') as $event) {
    if ($event instanceof Notification) {
        echo $event->text . \PHP_EOL;
    }
}
