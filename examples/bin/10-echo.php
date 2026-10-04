<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitRequest;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

require_once __DIR__ . '/../vendor/autoload.php';

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

$storage = new InMemoryStorage();
$sessionStore = new SessionStore($storage, 'demo-user');
$session = $sessionStore->create();
$conversation = new Conversation(
    new Agent(),
    $session,
    commands: new Commands($echo, new LeaveCommand()),
);

// The terminal host decides to leave when it receives an ExitRequest.
foreach (['/echo Hello from Conversation', '/exit', '/echo Unreached'] as $input) {
    $stream = $conversation->sendInput($input);
    foreach ($stream as $event) {
        if ($event instanceof Notification) {
            echo $event->text . \PHP_EOL;
        } elseif ($event instanceof ExitRequest) {
            echo 'The terminal host leaves.' . \PHP_EOL;
            break 2;
        }
    }
}
