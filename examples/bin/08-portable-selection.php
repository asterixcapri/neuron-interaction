<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;

require_once __DIR__ . '/../../vendor/autoload.php';

// This selection needs no AI provider. The Command owns validation and persistence.
$languageCommand = new class implements CommandInterface {
    public function name(): string
    {
        return '/language';
    }

    public function describe(): string
    {
        return 'Choose the preferred language';
    }

    public function run(CommandContext $context, string $value): void
    {
        if (!\in_array($value, ['English', 'Italian'], true)) {
            $context->requestSelection(new SelectionRequest($this->name(), 'Choose a language', [
                new SelectionOption('English', 'English'),
                new SelectionOption('Italian', 'Italian'),
            ]));
            return;
        }
        $context->configurationStore()->write('language', $value);
        $context->notify('Saved language: ' . $value);
    }
};
$conversation = new Conversation(new Agent(), new SessionStore(new InMemoryStorage(), 'demo-user'), commands: new Commands($languageCommand));

foreach ($conversation->submitInput('/language') as $event) {
    if (!$event instanceof SelectionRequest) {
        continue;
    }
    echo $event->prompt . \PHP_EOL;
    foreach ($event->options as $index => $option) {
        echo ($index + 1) . '. ' . $option->label . ' (' . $event->command . ' ' . $option->value . ')' . \PHP_EOL;
    }
    echo 'Choose a number (Enter to cancel): ';
    $line = \fgets(\STDIN);
    if ($line === false || \trim($line) === '') {
        // Cancellation belongs to the host: no input is sent to Conversation.
        break;
    }
    $number = \filter_var(\trim($line), \FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => \count($event->options)],
    ]);
    if ($number === false) {
        echo 'Invalid choice.' . \PHP_EOL;
        break;
    }
    $option = $event->options[$number - 1];
    // A browser sends these two strings in a later HTTP request. Its backend
    // restores the Session and stores and submits this input to a new Conversation.
    foreach ($conversation->submitInput(new CommandInput($event->command, $option->value)) as $response) {
        if ($response instanceof Notification) {
            echo $response->text . \PHP_EOL;
        }
    }
}
