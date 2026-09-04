<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\SessionCommandKit;
use NeuronInteraction\Examples\BackendControls;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\Sessions;
use NeuronInteraction\Storage\InMemoryStorage;

require dirname(__DIR__) . '/vendor/autoload.php';

$storage = new InMemoryStorage(); // A real backend may share FileStorage across requests.
$sessions = new Sessions($storage);
$sessions->start()->addMessage(new UserMessage('A conversation to reopen'));
$commands = new Commands([new SessionCommandKit(), new HelpCommand(), new LeaveCommand()]);
$inputs = new InputHistory($storage);

// This callback belongs to the Host Application. Replace it with its normal
// Agent flow; delivery/streaming of the response also belongs to that flow.
$submitPrompt = static function (Agent $agent, string $prompt): void {
    echo 'Host received a prompt: ' . $prompt . PHP_EOL;
};

// First request: the route supplies a neutral identifier and raw arguments.
$first = new BackendControls(new Agent(), $commands, $sessions, $submitPrompt);
$inputs->record('/resume'); // The original submission in this Adapter's syntax.
$execution = $commands->run('/resume', new CommandArguments(), $first);

if ($execution->status !== 'completed' || $first->selection === null) {
    throw new RuntimeException('The first invocation did not request selection.');
}

$response = json_encode($first->selection, JSON_THROW_ON_ERROR);
echo $response . PHP_EOL;

// A frontend displays the response and later submits the command and chosen
// option value. Decode the first response to simulate that separate round trip.
$decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
$options = is_array($decoded) ? ($decoded['options'] ?? null) : null;
$option = is_array($options) ? ($options[0] ?? null) : null;
$selected = is_array($option) ? ($option['value'] ?? null) : null;
$command = is_array($decoded) ? ($decoded['command'] ?? null) : null;

if (!is_string($selected) || !is_string($command)) {
    throw new RuntimeException('A command and selected value are required.');
}

// Second request: fresh controls, no retained selection or first-request Agent.
$second = new BackendControls(new Agent(), $commands, $sessions, $submitPrompt);
$execution = $commands->run($command, new CommandArguments($selected), $second);

if ($execution->status !== 'completed') {
    throw new RuntimeException('The selected Session could not be resumed.');
}

echo $second->agent()->getChatHistory()->getMessages()[0]->getContent() . PHP_EOL;

// These ordinary Commands use this backend's own output and lifecycle effects.
$commands->run('help', new CommandArguments(), $second);
$commands->run('exit', new CommandArguments(), $second);

if ($second->notices === [] || !$second->stopped) {
    throw new RuntimeException('Shared Help and Leave did not reach the backend controls.');
}
