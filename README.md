# Neuron Interaction

Neuron Interaction provides conversation sessions, commands, input history,
user preferences and response stopping for applications built with
[Neuron AI](https://github.com/neuron-core/neuron-ai).

Use it to save and resume conversations, offer reusable commands, ask users
to choose an option, recall previous inputs and persist their preferences.
Use the modules independently in a terminal or web application. Your application
controls input and presentation; Conversation dispatches commands and executes
their prompts in one stream.

## Installation

Requires PHP 8.4.1+ and Neuron AI 4.

Run this command in your application's directory:

```bash
composer require asterixcapri/neuron-interaction
```

For applications using Neuron AI 3, install the `0.8` series:

```bash
composer require asterixcapri/neuron-interaction:^0.8
```

## What it provides

- **SessionStore and Storage** save, list and resume conversations using memory, JSON files or your own storage implementation.
- **Stop signal** shares stop requests across handlers and processes through a signal used by Neuron AI's native stoppable HTTP client.
- **Input history** records submissions and supports recalling previous inputs.
- **Commands** provide `/clear`, `/resume`, `/help`, `/exit` and custom behavior.
- **Configuration** stores user preferences such as the selected model.
- **User message processing** prepares messages for the Agent and projects them for display.

## SessionStore and Storage

Bind the Agent to a stored conversation. Subsequent history
updates are persisted automatically:

```php
use NeuronAI\Agent\Agent;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;

$storage = new FileStorage(__DIR__ . '/interaction-state');
$sessionStore = new SessionStore($storage, 'local-user');
$agent = Agent::make();
$agent = $sessionStore->create()->bindToAgent($agent);
```

Use `list()` to list conversations and `get($key)` to reopen one, then
bind it with `$agent = $session->bindToAgent($agent)`. Always keep the returned Agent: binding returns a copy. Supply the current user's
identity instead of `local-user`; reads and listings are scoped to that user.

Use `InMemoryStorage` for transient state, or implement `StorageInterface`
for your application's persistence. See [Sessions and Storage](docs/sessions.md)
for listing, deletion, metadata and filtering. Use `$session->getMessages()` to read
the full conversation, including archived messages.

## Stop a response

Pass Neuron AI's native `StoppableHttpClient` to the AI provider, then configure
the Agent with that provider. Share a `StopSignal` between the HTTP client and the code handling
stop requests. Creating the signal alone does not enable stopping: the provider
must use the stoppable client with `shouldStop: $stopSignal->stopCallback()`.

The application chooses the key identifying the response:

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Storage\FileStorage;

$storage = new FileStorage(__DIR__ . '/interaction-state');
$stopSignal = new StopSignal(storage: $storage, key: $chatId);
$client = new StoppableHttpClient(
    client: new CurlHttpClient(),
    shouldStop: $stopSignal->stopCallback(),
);

$agent = Agent::make()->setThreadId($chatId);
$agent->setAiProvider(new OpenAIResponses(
    key: $apiKey,
    model: $model,
    httpClient: $client,
));

// Clear any previous stop request before starting a new response.
$stopSignal->clear();
$stream = $agent->stream(new UserMessage($prompt));
foreach ($stream as $event) {
    if ($event instanceof TextChunk) {
        echo $event->content;
    }
}

$response = $stream->getReturn()->getMessage();
```

While the Agent is streaming, a separate stop endpoint or process can request
that it stop. Use the same storage location and authorized key:

```php
$stopSignal = new StopSignal(
    storage: new FileStorage(__DIR__ . '/interaction-state'),
    key: $chatId,
);
$stopSignal->request();
```

Connect `request()` to your application's stop button, endpoint or keyboard
action. The stream detects and consumes the signal, allowing Neuron to finalize
the partial response, which you can retrieve with `$stream->getReturn()->getMessage()` after consuming the stream. A stop before any text raises Neuron's `ProviderException`.
No stop check is needed in the consumer loop. Only `request()` writes a stop document; `clear()` removes it and `isRequested()` reads its state.
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

Choose which Commands your application offers and register them explicitly:

```php
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Conversation;

$session = $sessionStore->create();
$conversation = new Conversation($agent, $session);
$conversation->setCommands(new Commands(
    new ClearCommand($sessionStore), new ResumeCommand($sessionStore), new HelpCommand(), new ExitCommand(),
));
foreach ($conversation->sendInput('/resume') as $event) {
    // Present native Agent events and interaction events here.
}
```

The host presents Notification, SelectionRequest, ExitRequest, SessionChanged and
AgentChanged. A chosen option returns as CommandInput with command and value, even
across HTTP requests; cancelling submits nothing. Commands have no pending picker
state. Unknown commands produce Error feedback; the optional commandAdmission
closure lets the host refuse an invocation with Warning feedback.

### Write a Command

A Command receives the concrete context and returns void. It registers prompts
for Conversation to execute in the same stream:

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;

final class HelloCommand implements CommandInterface
{
    public function name(): string { return '/hello'; }
    public function describe(): string { return 'Say hello.'; }
    public function run(CommandContext $context, string $value): void
    {
        $context->notify('Greeting requested.');
        $context->promptAgent(new UserMessage('Say hello to ' . $value));
    }
}
```

See the [Command reference and migration guide](docs/commands.md) for request
ordering, state access, selection, admission and error propagation.

## Examples

The examples demonstrate Interaction's advantages around a real Neuron Agent:
managing multiple Sessions, reusable Commands, Selections, response interruption,
message processors, Input history and user preferences. They print readable
messages directly, with every Agent response shown in streaming.

```bash
composer --working-dir=examples install
# Configure OPENAI_API_KEY in examples/.env (see examples/.env.example).
composer --working-dir=examples sessions
```

| Run from `examples/` | Demonstrates |
| --- | --- |
| `composer sessions` | List Sessions, inspect their messages and switch between independent contexts. |
| `composer commands` | Use `/help`, `/clear` and `/exit` in an interactive conversation. |
| `composer selection` | Choose a Session through presentation-neutral Selection options. |
| `composer custom-selection` | Define `/model` and choose a model through Selection options. |
| `composer interruption` | Stop an HTTP response and retain its partial message. |
| `composer processors` | Expand a file reference for the Agent and project saved content for display. |
| `composer input-history` | Recall original inputs and recover a draft. |
| `composer preferences -- Italian` | Persist a preference; run again without arguments to read it. |

Examples have their own Composer dependencies. See the [walkthrough](examples/README.md)
for setup, expected results and the Host's responsibilities.

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

## User message processing

Implement `NeuronInteraction\Message\UserMessageProcessorInterface` to prepare
complete `UserMessage` objects for the Agent and project them for display.
`UserMessageProcessors` can be populated like `Commands`:

```php
$processors = new UserMessageProcessors($first, $second);
$processors->addProcessor($third);
```

`addProcessor()` mutates the collection and returns the same instance. Register
processors before running the host; `all()` returns them in registration order.

`UserMessageProcessors` composes preparation in registration order and display
in reverse order. Processors return new messages without modifying the originals;
text, attachments and metadata can be handled together. See
[User message processing](docs/messages.md) for the contract.

## Development

```bash
composer install
composer test
composer stan
composer cs
```

## Conversation execution

The Host Application configures a Neuron Agent and composes a runtime:

```php
use NeuronInteraction\Conversation;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;

$conversation = new Conversation($agent, $session);
$conversation->setUserMessageProcessors($processors);
$stream = $conversation->sendInput(new UserMessage('Hello'));
foreach ($stream as $chunk) {
    if ($chunk instanceof TextChunk) {
        echo $chunk->content;
    }
}
$state = $stream->getReturn(); // Native Neuron AgentState.
```

Preparation is synchronous; consuming the generator starts execution. Original
Neuron objects, order and final state pass through unchanged. The runtime has
no busy state or execution lock; callers coordinate overlapping submissions.
Provider failures propagate unchanged. If you break iteration and keep the generator, it remains suspended;
release it with `unset($stream)` when abandoning it.

Pending inputs, queue policy, rendering and scheduling belong to the client:
React in a web app, or Neuron TUI in a terminal. Command-generated prompts use
the same processing pipeline when reached in the stream; processors preserve
recognized expanded content. Each client registers its own Commands and decides
visibility and admission through the Conversation admission closure. The runtime
binds Agent/Session replacements; the backend controller authorizes Session access.

With `setStopSignal()`, `requestInterruption()` requests the existing HTTP response
stop during execution. The host must wire the same signal into Neuron's stoppable
HTTP client. Separate requests can signal shared storage directly; disconnecting
the frontend alone does not guarantee cancellation.

See [Conversation and the frontend/backend boundary](docs/conversation-runtime.md)
for TUI, React/controller examples, lifecycle and response-stop limits.
