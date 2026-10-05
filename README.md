# Neuron Interaction

Neuron Interaction adds saved conversations, application commands, user preferences,
input history and response stopping to PHP applications built with
[Neuron AI](https://github.com/neuron-core/neuron-ai).

You configure a Neuron Agent with its provider, instructions and tools. Interaction
binds that Agent to a saved session and provides one method for submitting messages
and commands. Your application receives a stream of Neuron objects and interaction
events, and decides how to display them in a terminal or web interface.

## Contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [The main objects](#the-main-objects)
- [Submitting input and consuming the stream](#submitting-input-and-consuming-the-stream)
- [Sessions and saved messages](#sessions-and-saved-messages)
- [Commands and events](#commands)
- [User preferences](#user-preferences)
- [Input history](#input-history)
- [Message processors](#message-processors)
- [Stopping an HTTP response](#stopping-an-http-response)
- [Storage and web integration](#storage-and-web-integration)
- [Runnable examples](#runnable-examples)
- [Development](#development)

## Installation

Requires PHP 8.4.1+ and Neuron AI 4.

```bash
composer require asterixcapri/neuron-interaction
```

For Neuron AI 3 applications, use the `0.8` series. The API described below is for
Neuron AI 4:

```bash
composer require asterixcapri/neuron-interaction:^0.8
```

## Quick start

This script creates a saved session, sends a message and prints the response as
it arrives. Set `OPENAI_API_KEY` and `OPENAI_MODEL` in your environment first.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;

$apiKey = getenv('OPENAI_API_KEY');
$model = getenv('OPENAI_MODEL');
if ($apiKey === false || $apiKey === '' || $model === false || $model === '') {
    throw new RuntimeException('Set OPENAI_API_KEY and OPENAI_MODEL.');
}

$provider = new OpenAIResponses(key: $apiKey, model: $model);
$agent = Agent::make();
$agent->setAiProvider($provider);

$storage = new FileStorage(__DIR__ . '/interaction-state');
$sessionStore = new SessionStore($storage, 'local-user');
$session = $sessionStore->create();
$conversation = new Conversation($agent, $session);

$stream = $conversation->sendInput('What is a PHP generator?');
foreach ($stream as $event) {
    if ($event instanceof TextChunk) {
        echo $event->content;
        flush();
    }
}

$state = $stream->getReturn(); // Neuron AgentState.
$sessionKey = $conversation->session()->getKey(); // Save this to resume later.
```

History updates are persisted automatically. In an authenticated application,
replace `local-user` with the current user's stable identity. A new call to
`create()` starts a separate session; reopen an existing one with
`$sessionStore->get($sessionKey)`.

The following examples build on the objects above unless stated otherwise.

## The main objects

| Object | Responsibility |
| --- | --- |
| `Agent` (Neuron AI) | Calls the provider, runs tools and maintains the model context. |
| `StorageInterface` | Persists JSON documents. Included implementations use files or memory. |
| `SessionStore` | Creates, lists, retrieves and deletes sessions for one user. |
| `Session` | Identifies a saved conversation and exposes its messages, title and metadata. |
| `Conversation` | Binds the Agent to a Session, dispatches input and returns a stream. |
| `CommandInterface` | Implements an application action invoked by a slash command. |
| `EventInterface` | Identifies Interaction's events for the application to handle. |

`Conversation` requires an Agent and an explicit Session. It binds the Agent
internally; use `$conversation->agent()` to obtain the bound instance.
You can also use the modules independently. When binding a Session directly,
keep the returned Agent: `bindToAgent()` returns a copy.

```php
$agent = $session->bindToAgent($agent);
```

Your application owns rendering, authentication, input queues and coordination
between concurrent requests. Interaction supplies the execution and persistence
APIs; it does not provide an HTTP endpoint or a UI.

## Submitting input and consuming the stream

`sendInput()` accepts a string, a Neuron `UserMessage`, or a `CommandInput`:

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandInput;

$stream = $conversation->sendInput('Hello');
$stream = $conversation->sendInput('/resume saved-session-key');
$stream = $conversation->sendInput(new CommandInput('/resume', $sessionKey));
$stream = $conversation->sendInput(new UserMessage('/this is literal message text'));
```

Each line above illustrates a separate submission: consume its returned generator
to execute it. A string matching slash-command syntax dispatches a command;
an explicit `UserMessage` always goes to the Agent. Command identifiers start
with `/` followed by letters, digits, underscores or hyphens, and lookup is case
sensitive. Arguments remain strings for the command to validate. Blank string
input produces an empty stream.

Ordinary messages pass through configured processors synchronously in
`sendInput()`. Agent execution and command dispatch begin when you iterate the
returned generator. Neuron's native objects, including text, tools and reasoning,
pass through without conversion. After fully consuming the stream, `getReturn()`
returns the last `AgentState`, or `null` if no Agent prompt executed.

Preparation, provider and storage exceptions propagate to your application.
Handle preparation errors around `sendInput()` and execution errors around
iteration. Breaking the loop pauses the generator; it does not request a stop.
Release an abandoned generator and its other references, for example with
`unset($stream)`.

## Sessions and saved messages

`SessionStore` scopes retrieval, listing and deletion to the supplied user:

```php
$session = $sessionStore->create(['projectId' => 'alpha']);
$session->setTitle('PHP generator discussion');
$session->setMetadata('branchName', 'main');

foreach ($sessionStore->list(['projectId' => 'alpha']) as $summary) {
    echo $summary->getKey() . ': ' . ($summary->getTitle() ?? 'Untitled') . PHP_EOL;
}

$restored = $sessionStore->get($sessionKey);
if ($restored === null) {
    throw new RuntimeException('Session not found for this user.');
}
$conversation->useSession($restored);
```

`list()` returns `SessionSummary` objects, most recently used first, with
`getKey()`, `getTitle()`, `getLastUsedAt()` and `getSize()` (JSON data size in bytes).
Sessions appear in listings once they contain user-authored text or attachments.
Empty sessions can still be retrieved by key. `get()` returns `null` for missing
sessions or sessions belonging to another user. `delete($key)` removes that
user's session and does nothing if it is absent.

Metadata keys use camelCase and values are strings. Use `getMetadata()`,
`setMetadata($key, $value)` and `removeMetadata($key)` to manage them.
Listing filters use exact equality and combine with AND. Updating metadata or
a title preserves messages and the last-used time. Titles are optional;
`setTitle()` requires nonblank text.

Read saved history through the Session or Conversation:

```php
$all = $session->getMessages(); // Includes archived messages.
$recent = $conversation->getDisplayMessages(limit: 50);
if ($recent !== []) {
    $older = $conversation->getDisplayMessages(
        limit: 50,
        before: $recent[0]->getId(),
    );
}
```

`getMessages()` returns saved Neuron messages. `getDisplayMessages()` additionally
applies configured display processors to user messages. Both accept `limit` and
`before`: pages are chronological, `before` excludes the message with that ID,
and an unknown ID returns an empty page. Reads load the current saved history.
The Agent's active model context may be shorter because Neuron archives older
messages while trimming its context window.

To generate a title with an AI provider:

```php
use NeuronInteraction\Session\SessionTitleGenerator;

$title = (new SessionTitleGenerator($provider, $session))->generate();
```

This saves a generated title without changing messages. It returns `null` if no
title was generated, including when a title already exists, there is no usable
conversation, or the attempt limit has been reached. The default limit is three
provider requests per session; set `maxAttempts:` to change it. Attempts, including
failed requests, are persisted in session metadata. Your application decides
when to generate titles and coordinates concurrent calls.

## Commands

Register the commands your application offers. No commands are enabled by default.

```php
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\ResumeCommand;

$conversation->setCommands(new Commands(
    new ClearCommand($sessionStore),
    new ResumeCommand($sessionStore),
    new HelpCommand(),
    new ExitCommand(),
));
```

| Command | Behavior |
| --- | --- |
| `/clear` | Selects a new empty Session, retaining the previous conversation. |
| `/resume` | Offers saved sessions to choose from; `/resume key` opens one directly. |
| `/help` | Produces a notification listing registered commands and descriptions. |
| `/exit` | Emits a request for the application to leave the interaction. |

Constructors accept a custom `name:`. Extend a registry with
`addCommand($first, $second)`; registering the same name replaces its command.
`all()` lists commands and `named($identifier)` retrieves one or returns `null`.
`setCommands()` replaces the entire registry.

### Writing a command

A command implements three methods and receives a fresh `CommandContext` for
each invocation:

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;

final class ExplainCommand implements CommandInterface
{
    public function name(): string
    {
        return '/explain';
    }

    public function describe(): string
    {
        return 'Explain a topic.';
    }

    public function run(CommandContext $context, string $value): void
    {
        if (trim($value) === '') {
            $context->notify('Usage: /explain topic');
            return;
        }

        $context->notify('Starting explanation.');
        $context->promptAgent(new UserMessage('Explain ' . $value));
        $context->notify('Explanation completed.');
    }
}

$conversation->commands()->addCommand(new ExplainCommand());
$stream = $conversation->sendInput('/explain PHP generators');
```

The application consumes this stream to receive the first notification, the
Agent's response events, then the final notification. Commands return `void`;
they register prompts with `promptAgent()` rather than calling `Agent::stream()`.
Multiple prompts execute sequentially and use the configured message processors.

| Context method | Use |
| --- | --- |
| `agent()`, `session()` | Access current state. |
| `useAgent($agent)`, `useSession($session)` | Bind a replacement immediately and register a change event. |
| `configurationStore()` | Read and write the application's preferences. |
| `commands()` | Get the list of registered commands. |
| `notify($text, $level)` | Register feedback; level defaults to `NotificationLevel::Info`. |
| `promptAgent($message)` | Register a Neuron `UserMessage` for execution. |
| `requestSelection($request)` | Register choices for the application to display. |
| `requestExit()` | Ask the application to leave the interaction. |

Inject dependencies such as `SessionStore` into your command's constructor.
`NotificationLevel` lives in `NeuronInteraction\Command` and has `Info`, `Warning`
and `Error` cases. If you change an Agent property and need to notify the UI,
call the context's `useAgent()` with that Agent.

State changes happen during `run()`; registered requests are processed after it
returns, in registration order. If a command throws, requests already registered
are processed before that exception propagates. A failure while processing a
request stops the remaining requests. There is no rollback or automatic retry.

### Handling events and selections

All five interaction events live in `NeuronInteraction\Event` and implement
`EventInterface`. Neuron's native stream objects do not implement this interface.

| Event | Public properties | Application action |
| --- | --- | --- |
| `Notification` | `text`, `level` | Display feedback. |
| `SelectionRequest` | `command`, `prompt`, `options`, `description` | Present choices and submit the selected value later. |
| `SessionChanged` | `session` | Refresh the selected session and displayed history. |
| `AgentChanged` | `agent` | Refresh the selected Agent or model in the UI. |
| `ExitRequest` | None | Decide how to end the interaction. |

For example, an application can handle text and notifications in the same loop:

```php
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Event\SelectionRequest;

foreach ($conversation->sendInput('/resume') as $event) {
    if ($event instanceof TextChunk) {
        echo $event->content;
    } elseif ($event instanceof Notification) {
        echo $event->level->name . ': ' . $event->text . PHP_EOL;
    } elseif ($event instanceof SelectionRequest) {
        // Render $event->prompt and the labels in $event->options.
        // Retain $event->command and each option's value for the next submission.
    }
}
```

A custom command can offer choices with:

```php
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Event\SelectionRequest;

$context->requestSelection(new SelectionRequest(
    command: '/language',
    prompt: 'Choose a language',
    options: [
        new SelectionOption('English', 'English'),
        new SelectionOption('Italian', 'Italian', 'Respond in Italian'),
    ],
));
```

This fragment belongs inside a command's `run()` method. Options have `value`,
`label` and optional `description`; the options list must be nonempty and ordered.
`SelectionOption` is a support type in `NeuronInteraction\Command`.

When the user chooses, submit a new input using the request's command and option
value, and consume the new stream:

```php
use NeuronInteraction\Command\CommandInput;

$stream = $conversation->sendInput(new CommandInput('/language', 'Italian'));
```

Register a `/language` command that handles that value; an empty value can display
the choices, while a selected value applies the preference. The command must
validate submitted values, including values never offered by the UI.
Selections contain no callback or pending server state. This works across HTTP
requests: reconstruct the Conversation with the correct session and stores,
then submit the `CommandInput`. Cancelling a selection submits nothing.

`ExitRequest` is advisory. It does not stop an Agent response or terminate the
PHP process. The application decides how to handle it.

### Command admission

Unknown commands emit an Error notification. To refuse a known command under
application-specific conditions, supply `admitCommand:` when constructing the
Conversation:

```php
use NeuronInteraction\Command\CommandInterface;

$conversation = new Conversation(
    $agent,
    $session,
    admitCommand: static fn(CommandInterface $command): bool => $command->name() !== '/clear',
);
```

The closure runs before each command invocation, including selection responses.
Returning `false` emits a Warning notification without running the command.
This controls command admission; your application still handles session access
and concurrent execution.

## User preferences

Use `ConfigurationStore` to persist preferences for one user and share them with
commands through the Conversation:

```php
use NeuronInteraction\Configuration\ConfigurationStore;

$settings = new ConfigurationStore($storage, 'local-user');
$conversation->setConfigurationStore($settings);

$settings->write('model', $model);
$settings->write('temperature', 0.5);
$model = $settings->read('model', $model);
$temperature = $settings->read('temperature', 0.5);
$settings->delete('obsoleteOption');
$preferences = $settings->entries();
```

Writes persist immediately and preserve other preferences. Values must be
JSON-compatible scalars, `null` or PHP arrays. Keys are literal preference names.
The fallback passed to `read()` selects the expected PHP type; a missing or
incompatible value returns that fallback without rewriting storage.

| Read | Accepted stored value |
| --- | --- |
| `read('model', 'default-model')` | Nonempty string. |
| `read('retries', 3)` | Integer. |
| `read('temperature', 0.5)` | Float; integers are not converted. |
| `read('enabled', false)` | Boolean. |
| `read('tools', [])` | Array; validate its elements in your application. |
| `read('model')` | Nonempty string, otherwise `null`. |

An empty string fallback is invalid. `entries()` returns raw stored values,
including nulls and empty strings. Storage errors propagate.
A Conversation without a supplied ConfigurationStore uses its own transient
in-memory store. Persisting a model preference does not configure the Agent's
provider automatically; apply the setting in your application or command.

## Input history

Input history records what the user submitted, separately from the Agent's saved
conversation. Use it to implement recall in a terminal or web composer:

```php
use NeuronInteraction\InputHistory\InputHistory;

$inputs = new InputHistory($storage);
$conversation->setInputHistory($inputs);
$submitted = $inputs->list(); // UserMessage objects, oldest first.
```

With this configured, `sendInput()` records original messages and commands
before processing or execution, even if those later fail. Prompts generated by
commands are excluded. Attachments and metadata are preserved; blank inputs are
ignored and only consecutive identical submissions are collapsed.

The application owns navigation, keyboard handling and draft restoration.
You can also call `append(new UserMessage($text))` directly when recording input
outside Conversation. InputHistory has no user ID parameter: its entries are
shared by all instances using the same storage. Give each user a separate storage
location or a storage adapter that scopes access when histories must be private.

## Message processors

Processors prepare user messages for the Agent and project saved messages for
display. For example, a processor can expand `@README.md` into file contents for
the model and restore the original reference when showing saved history.

Implement `NeuronInteraction\Message\UserMessageProcessorInterface`:

```php
public function forAgent(UserMessage $message): UserMessage;
public function forDisplay(UserMessage $message): UserMessage;
```

Return a new message without modifying the input or its content blocks, and
preserve attachments and metadata you do not deliberately transform.
`forDisplay()` should be deterministic and have no side effects.

For reference expansion, extend `AbstractUserMessageTagProcessor`. This
`FileUserMessageProcessor` expands `@` references to files within a configured
directory, such as `@README.md` or `@src/Conversation.php`:

```php
use NeuronInteraction\Message\AbstractUserMessageTagProcessor;
use NeuronInteraction\Message\UserMessageProcessors;

final class FileUserMessageProcessor extends AbstractUserMessageTagProcessor
{
    public function __construct(private readonly string $directory) {}

    protected function tagName(): string
    {
        return 'file';
    }

    protected function contentFor(string $reference): ?string
    {
        $directory = realpath($this->directory);
        if ($directory === false) {
            throw new RuntimeException('File directory does not exist.');
        }

        $root = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $path = realpath($root . $reference);
        if ($path === false || !str_starts_with($path, $root) || !is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Cannot read file: ' . $reference);
        }
        return $content;
    }
}

$conversation->setUserMessageProcessors(new UserMessageProcessors(
    new FileUserMessageProcessor(__DIR__),
));
$stream = $conversation->sendInput('Summarize @README.md');
```

The base class expands the reference to `<file name="README.md">...</file>`,
preserves the original text for display and avoids expanding its prepared blocks
again. Returning `null` leaves a reference unchanged. Override `referencePrefix()`
(default `@`) or `attributesFor()` when needed.

A `UserMessageProcessors` collection applies preparation in registration order
and display in reverse order. `addProcessor()` extends the collection;
`all()` returns its processors. Ordinary messages are prepared in `sendInput()`;
command-generated prompts are prepared when reached in the stream. Prepared
messages require text or a non-text attachment.

Show newly submitted input directly in the UI. When reopening saved history,
use `getDisplayMessages()` to apply the display pipeline without changing storage.

## Stopping an HTTP response

Share a `StopSignal` between your stop handler and Neuron's native
`StoppableHttpClient`. The provider must use that client; registering a signal
with Conversation alone does not enable stopping.

Using the provider credentials and Session from the quick start:

```php
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronInteraction\Interruption\StopSignal;

$responseKey = bin2hex(random_bytes(16)); // Expose this execution key to your stop handler.
$signal = new StopSignal($storage, $responseKey);
$client = new StoppableHttpClient(
    client: new CurlHttpClient(),
    shouldStop: $signal->stopCallback(),
);

$agent->setAiProvider(new OpenAIResponses(
    key: $apiKey,
    model: $model,
    httpClient: $client,
));
$conversation = new Conversation($agent, $session);
$conversation->setStopSignal($signal);

$stream = $conversation->sendInput('Explain PHP generators in detail.');
foreach ($stream as $event) {
    if ($event instanceof TextChunk) {
        echo $event->content;
        flush();
    }
}
$state = $stream->getReturn();
```

Conversation clears stale stop requests when an Agent prompt starts. While it
streams, a handler in the same process can call
`$conversation->requestInterruption()`. A separate endpoint can write the same
signal using the same storage location and authorized execution key:

```php
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Storage\FileStorage;

$signal = new StopSignal(new FileStorage(__DIR__ . '/interaction-state'), $responseKey);
$signal->request();
```

The provider callback reads and consumes the signal. Keep consuming the stream
so Neuron can finalize and persist the partial response. Stopping before any text
raises Neuron's `ProviderException`. If using `Agent::stream()` directly, clear
the signal yourself before starting.

`request()` writes the flag, `isRequested()` reads it, and `clear()` removes it.
`stopCallback(onPoll: ..., pollInterval: 0.01)` optionally lets a terminal host
process input before polling; there is no background polling task.
`supportsResponseStop()`, `responseStopRequested()` and `responseWasStopped()`
expose Conversation's local signal state. The latter two track requests made
through that instance, not requests sent by a separate endpoint.

Concurrent responses need distinct keys and separate clients/callbacks. Separate
stream and stop endpoints need workers that can run concurrently. An in-memory
signal works only when handlers share the same Storage instance in one process.
Stopping an HTTP response does not cancel running local tools, prevent later
provider calls in the turn, or guarantee cancellation of remote generation.

## Storage and web integration

Use `FileStorage($directory)` for JSON files or `InMemoryStorage` for transient
state. Sessions, preferences, input history and stop signals can share a storage
implementation. For database or distributed storage, implement `StorageInterface`:

| Method | Contract |
| --- | --- |
| `create($namespace, $data, $metadata, $key)` | Return a StoredDocument with the supplied or generated key; fail if the key already exists. |
| `read($namespace, $key)` | Return a StoredDocument or `null` when absent. |
| `write($namespace, $key, $data, $metadata)` | Create or replace the document and return it. |
| `delete($namespace, $key)` | Remove the document if present. |
| `entries($namespace, $metadata)` | Iterate documents matching all metadata filters exactly. |

`StoredDocument` exposes `key`, `data`, `metadata` and `size()`. Documents contain
JSON-compatible array data and string metadata. Included adapters require
namespaces and keys to start with a letter or digit, followed by letters, digits,
dots, underscores or hyphens. Metadata names use camelCase.

For each web request, authenticate the user, build their SessionStore, retrieve
or create the authorized Session, configure the Agent, and construct Conversation.
Restore commands, preferences and processors before submitting input. Serialize
stream objects into your chosen HTTP protocol; PHP objects and EventInterface
are not themselves a wire format. Return the session key to the client so later
requests can reopen it. Choices return as command identifiers and values.

Coordinate writes when several requests or clients can operate on the same
session or shared state. Conversation provides no busy flag, execution lock or
pending-input queue, and storage does not provide transactions across a turn.
Queue pending messages in the client or application layer and choose how errors,
reconnects and overlapping requests should behave.

## Runnable examples

Clone this repository and install the examples' dependencies:

```bash
composer --working-dir=examples install
# Configure examples/.env using examples/.env.example.
composer --working-dir=examples input
```

| Run from the repository root | Demonstrates |
| --- | --- |
| `composer --working-dir=examples input` | Minimal message submission and streaming. |
| `composer --working-dir=examples sessions` | Saved sessions and independent conversation contexts. |
| `composer --working-dir=examples commands` | Built-in commands. |
| `composer --working-dir=examples selection` | Reopening a session through choices. |
| `composer --working-dir=examples custom-selection` | A custom model-selection command. |
| `composer --working-dir=examples interruption` | Stopping a response and retaining partial output. |
| `composer --working-dir=examples processors` | File reference expansion and display projection. |
| `composer --working-dir=examples input-history` | Input recall and draft restoration. |
| `composer --working-dir=examples configuration` | Persistent user preferences. |
| `composer --working-dir=examples custom-command` | A command that changes the response language. |

## Development

```bash
composer install
composer test
composer stan
composer cs
```

Apply PHP formatting with `composer cs:fix`.
