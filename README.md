# Neuron Interaction

Neuron Interaction provides conversation sessions, commands, input history,
user preferences and response stopping for applications built with
[Neuron AI](https://github.com/neuron-core/neuron-ai).

Use it to save and resume conversations, offer reusable commands, ask users
to choose an option, recall previous inputs and persist their preferences.
Use the modules independently in a terminal or web application. Your application
controls the UI, Agent execution and streaming; commands use an Adapter to
connect to its input and output.

## Installation

Requires PHP 8.4.1+. The `0.8.x` branch supports Neuron AI 3.

Run this command in your application's directory:

```bash
composer require asterixcapri/neuron-interaction
```

Composer also installs Neuron AI as a required dependency. If you install Neuron
TUI, Neuron Interaction is already included as its dependency.

## What it provides

- **Sessions** save, list and resume Neuron AI conversations.
- **Input history** records submissions and supports recalling previous inputs.
- **Commands** provide `/clear`, `/resume`, `/help`, `/exit` and custom behavior.
- **Selections** let commands ask users to choose an option, including across HTTP requests.
- **Configuration** stores user preferences such as the selected model.
- **Response stop** interrupts HTTP streaming while letting Neuron finalize its partial response.
- **Storage** provides memory and JSON-file implementations, with an interface for your own storage.

## SessionStore and Storage

Install a stored conversation as the Agent's chat history. Subsequent history
updates are persisted automatically:

```php
use NeuronAI\Agent\Agent;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;

$storage = new FileStorage(__DIR__ . '/interaction-state');
$sessionStore = new SessionStore($storage, 'local-user');
$agent = new Agent();
$agent->setChatHistory($sessionStore->create());
```

Use `summaries()` to list conversations and `read($key)` to reopen one, then
install it with `$agent->setChatHistory($history)`. Supply the current user's
identity instead of `local-user`; reads and listings are scoped to that user.

Use `InMemoryStorage` for transient state, or implement `StorageInterface`
for your application's persistence. See [Sessions and Storage](docs/sessions.md)
for listing, deletion, metadata and filtering.

## Stop a response

Share a `StopSignal` between the provider's HTTP client and the code handling
stop requests. The application chooses the key identifying the response:

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronInteraction\Http\StoppableHttpClient;
use NeuronInteraction\Http\StopSignal;
use NeuronInteraction\Storage\FileStorage;

$storage = new FileStorage(__DIR__ . '/interaction-state');
$stopSignal = new StopSignal(storage: $storage, key: $chatId);
$client = new StoppableHttpClient(
    inner: new GuzzleHttpClient(),
    stopSignal: $stopSignal,
);

$agent = new Agent();
$agent->setAiProvider(new OpenAIResponses(
    key: $apiKey,
    model: $model,
    httpClient: $client,
));

$stopSignal->clear();
foreach ($agent->stream(new UserMessage($prompt))->events() as $event) {
    if ($event instanceof TextChunk) {
        echo $event->content;
    }
}
```

In a separate stop endpoint, use the same storage location and authorized key:

```php
$stopSignal = new StopSignal(
    storage: new FileStorage(__DIR__ . '/interaction-state'),
    key: $chatId,
);
$stopSignal->request();
```

The stream detects and consumes the signal, allowing Neuron to finalize the
partial response. No stop check is needed in the consumer loop. Only `request()`
writes a stop document; `clear()` removes it and `isRequested()` reads its state.
Use the same `InMemoryStorage` instance when both handlers run in one process.

Concurrent responses need distinct keys, and separate HTTP requests need workers
that can run concurrently. Stopping does not cancel local tools or guarantee
remote generation has stopped. See the [response stop guide](docs/response-stop.md)
for polling, terminal integration and lifecycle details.

## Input history

Record user submissions and recall them later:

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\InputHistory\InputHistory;

$inputs = new InputHistory($storage);
$inputs->record(new UserMessage('/resume session-key'));
$inputs->record(new UserMessage('A message exactly as submitted'));
$submitted = $inputs->entries(); // Oldest first, across sessions.

$recalled = $inputs->older(new UserMessage('Unsubmitted draft'));
$newer = $inputs->newer(); // Restores the draft past the newest input.
```

Your application decides when to record input and handles keyboard events.
A web frontend can use `entries()` and navigate locally. See
[Input history](docs/input-history.md) for navigation state and storage behavior.

## Commands

Commands let users perform actions such as starting a new conversation or
reopening a saved one. The library includes:

| Command | What it does |
| --- | --- |
| `/clear` | Start an empty Session, keeping the previous conversation. |
| `/resume` | Choose a saved conversation, or reopen one by its key. |
| `/help` | List the available Commands. |
| `/exit` | Ask the application to end the interaction. |

Choose which Commands your application offers and mount them explicitly:

```php
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\ResumeCommand;

$commands = new Commands([
    new ClearCommand(),
    new ResumeCommand(),
    new HelpCommand(),
    new LeaveCommand(),
]);

// $adapter connects the Commands to your application.
$output = $commands->run('/resume', '', $adapter);
```

The Adapter decides how to display messages, offer choices and end the
interaction. A terminal Adapter can update the screen; a backend Adapter can
return response data. The Commands work with either.

To reopen a known Session, pass its key as the arguments:

```php
$output = $commands->run('/resume', $sessionKey, $adapter);
```

### Write a Command

A Command provides its name, a short description and the action to perform:

```php
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;

final class HelloCommand implements CommandInterface
{
    public function name(): string
    {
        return '/hello';
    }

    public function describe(): string
    {
        return 'Say hello.';
    }

    public function run(CommandAdapterInterface $adapter, string $value): void
    {
        $adapter->notify('Hello!');
    }
}

$commands->addCommand(new HelloCommand());
```

See the [Command and Adapter reference](docs/commands.md) for custom Adapters,
mounting, execution outcomes and error handling.

## Backend examples

These examples use [BackendAdapter](examples/BackendAdapter.php) to collect
Command messages and choices into response data. They run locally without an
HTTP server, API credentials or model requests.

Each backend example is self-contained and demonstrates one flow:

| Example | What it shows |
| --- | --- |
| [help.php](examples/help.php) | Execute Help and print the response. |
| [exit.php](examples/exit.php) | Ask the application to end the interaction. |
| [clear.php](examples/clear.php) | Start an empty Session while keeping the previous conversation. |
| [resume-by-key.php](examples/resume-by-key.php) | Reopen a conversation whose key is already known. |
| [resume-selection.php](examples/resume-selection.php) | Offer conversations, then receive the user's choice in a second request. |

Run any file after installing development dependencies:

```bash
php examples/help.php
php examples/resume-selection.php
```

In `resume-selection.php`, the first request offers saved conversations. The
second simulates the user's choice and reopens that conversation with a fresh
Agent and Adapter. The example provides its own sample data in memory.

## Configuration

Store preferences for the current user:

```php
use NeuronInteraction\Configuration\ConfigurationStore;

$settings = new ConfigurationStore($storage, 'local-user');
$settings->write('model', 'chosen-model');
$model = $settings->read('model', 'default-model');
```

The fallback determines the expected value type. Commands access the same store
through `$adapter->configurationStore()`. See
[ConfigurationStore](docs/configuration.md) for validation and the full API.

## Development

```bash
composer install
composer test
composer stan
composer cs
```
