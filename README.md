# Neuron Interaction

Neuron Interaction adds saved conversations, application commands, user preferences,
input history, response stopping and background subagents to PHP applications built with
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
- [Background subagents](#background-subagents)
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
use NeuronAI\Chat\History\FileMessageStore;
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
$messageStore = new FileMessageStore(__DIR__ . '/interaction-state/messages');
$sessionStore = new SessionStore($storage, $messageStore, 'local-user');
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
| `StorageInterface` | Persists ordered JSON-array entries by namespace and key, in files or memory. |
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
between concurrent requests. Configured Symfony locks serialize Conversation
turns; the host still owns scheduling. Interaction supplies the execution and persistence
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
When Agent execution fails, Conversation checks the committed history. If its
last message is a user input or tool result, it appends an assistant note marking
the interrupted response so the next input can start a new turn. Empty histories,
completed responses and tool calls without results remain unchanged. Recovery
runs only on execution errors; it never retries tools. If recovery also fails,
the stream emits an error Notification before propagating the original exception.
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

foreach ($sessionStore->list(['projectId' => 'alpha']) as $info) {
    echo $info->getKey() . ': ' . ($info->getTitle() ?? 'Untitled') . PHP_EOL;
}

$restored = $sessionStore->get($sessionKey);
if ($restored === null) {
    throw new RuntimeException('Session not found for this user.');
}
$conversation->useSession($restored);
```

`list()` returns `Session` objects, most recently used first, with
`getKey()`, `getTitle()`, `getCreatedAt()`, `getLastUsedAt()`, `hasContent()` and
`getMetadata()` and `getMetadataValue($key)`. Data getters use the fields loaded when each Session was constructed. Successful
writes update that instance; changes made through another instance are visible
after loading the Session again with `get()`. Ties use the Session key.
Listing reads small Session records without loading message histories.
Sessions appear in listings once they contain user-authored text or attachments.
Creation immediately persists an empty Session, which can be retrieved by key.
`get()` returns `null` for missing
sessions or sessions belonging to another user. `delete($key)` removes that
user's Session record and all its saved messages, and does nothing if it is absent.

Metadata keys are non-empty strings and values are strings. Names such as
`project_id` and `Branch.Name` are literal keys; dots do not denote nested fields. Use `getMetadata()` for the complete array or `getMetadataValue($key)` for one
value (null when absent, with empty strings preserved). Use
`setMetadata($key, $value)` and `removeMetadata($key)` to manage them.
Listing filters use exact equality and combine with AND. Updating metadata or
a title changes only the requested fields and preserves messages and the last-used time.
Each successfully completed Conversation turn advances last use and makes the
Session visible. Direct Agent use does not update the Session record. Replaying a
saved message ID leaves the original message intact; a successful Conversation
turn still advances the last-use time.
Titles are optional;
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
messages while trimming its context window. Clearing Neuron's history removes
active and archived messages and hides the empty Session from listings. Reappending
messages after summarization restores its content flag. The `/clear` command
starts a new Session and retains the previous one.

To enable automatic title generation, configure a SessionTitleGenerator on the Conversation,
just as you configure input history:

```php
use NeuronInteraction\Session\SessionTitleGenerator;

$conversation->setSessionTitleGenerator(new SessionTitleGenerator($provider));
```

Without this setter, Conversation does not generate titles. When configured, it
attempts generation when a message stream starts being consumed, before executing
the Agent response. The input contains saved user messages plus the prepared
current message; assistant messages are excluded. Commands that do not send a
message do not trigger generation, and existing titles are preserved.

The title agent decides whether the user has expressed a meaningful subject.
Greetings, wellbeing checks, small talk, and unclear intent should return `null`.
A `null` result leaves the title unset and consumes one attempt. The default limit
is five attempts per Session, including no-topic results and failed requests.
After the limit is reached, automatic title generation stops even if a concrete
subject emerges later. The prompt requests 2–5
words in the user's language; the code does not impose a length limit.

Generation is synchronous and adds latency before the Agent response starts,
instead of after its last chunk. A saved title emits `SessionTitleChanged`, with
`session` and `title` properties. Generation failures emit a warning `Notification`
and the Agent response still executes. The title can remain saved even if that
response later fails or is interrupted. If the selected session changes during
a turn, generation uses the session captured at its start. Hosts coordinate
overlapping executions and concurrent title requests.

To generate a title explicitly with an AI provider:

```php
use NeuronInteraction\Session\SessionTitleGenerator;

$title = (new SessionTitleGenerator($provider))->generate($session);
```

This saves a generated title without changing messages. It returns `null` if no
title was generated, including when a title already exists, there is no usable
user content, or the attempt limit has been reached. The default budget is five
attempts per session; set `maxAttempts:` to change it. No-topic results and failed
requests both consume the budget. The counter is persisted in session metadata. With explicit generation,
your application decides when to call the generator and coordinates concurrent calls.

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

$settings->set('model', $model);
$settings->set('temperature', 0.5);
$model = $settings->get('model', $model);
$temperature = $settings->get('temperature', 0.5);
$settings->delete('obsoleteOption');
$preferences = $settings->list();
```

Each user has one preferences record. Changes persist immediately through field
updates and preserve independent preference changes from other instances.
Values must be JSON-compatible scalars, `null` or PHP arrays. Keys are literal preference names.
The fallback passed to `get()` selects the expected PHP type; a missing or
incompatible value returns that fallback without rewriting storage.

| Lookup | Accepted stored value |
| --- | --- |
| `get('model', 'default-model')` | Nonempty string. |
| `get('retries', 3)` | Integer. |
| `get('temperature', 0.5)` | Float; integers are not converted. |
| `get('enabled', false)` | Boolean. |
| `get('tools', [])` | Array; validate its elements in your application. |
| `get('model')` | Nonempty string, otherwise `null`. |

An empty string fallback is invalid. `list()` returns raw stored values,
including nulls and empty strings. Storage errors propagate.
A Conversation without a supplied ConfigurationStore uses its own transient
in-memory store. Persisting a model preference does not configure the Agent's
provider automatically; apply the setting in your application or command.

## Input history

Input history records what the user submitted, separately from the Agent's saved
conversation. Use it to implement recall in a terminal or web composer:

```php
use NeuronInteraction\InputHistory\InputHistory;

$inputs = new InputHistory($storage, 'local-user');
$conversation->setInputHistory($inputs);
$submitted = $inputs->list(); // UserMessage objects, oldest first.
$recentInputs = $inputs->list(20); // Latest 20 inputs, still oldest first.
```

With this configured, `sendInput()` records original messages and commands
before processing or execution, even if those later fail. Prompts generated by
commands are excluded. Attachments and metadata are preserved; blank inputs are
ignored; reasoning-only inputs are skipped and meaningful non-text inputs retained.
Only consecutive identical authored submissions are collapsed, ignoring Neuron's
generated message ID.

The application owns navigation, keyboard handling and draft restoration.
You can also call `append(new UserMessage($text))` directly when recording input
outside Conversation. InputHistory requires the authenticated user's identity;
its namespace is shared across that user's Sessions and isolated from other users.
Every submission is a separate entry, and there is no automatic truncation.
A limit bounds the returned result, but file-backed recall may scan the whole
namespace. Comparing the last input and inserting its successor are separate
operations, so simultaneous identical submissions may both be recorded.

## Message processors

Processors prepare user messages for the Agent and project saved messages for
display. For example, a processor can expand `@README.md` into file contents for
the model and restore the original reference when showing saved history.

Implement `NeuronInteraction\Message\MessageProcessorInterface`:

```php
public function forAgent(UserMessage $message): UserMessage;
public function forDisplay(UserMessage $message): UserMessage;
```

Return a new message without modifying the input or its content blocks, and
preserve attachments and metadata you do not deliberately transform.
`forDisplay()` should be deterministic and have no side effects.

A processor can keep the person's text intact and append a `TextContent` with
file contents or Skill instructions. Mark the generated block in its metadata.
`forDisplay()` returns a copy without that block; the Host Application can call
`getContent()` on the copy as usual. The Agent and saved History retain the full
message. Reference recognition and loading belong to the concrete processor.

A `MessageProcessors` collection applies preparation in registration order
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

## Background subagents

Enable delegation on a Conversation with `setSubagents()`. A parent can then use
`spawn_subagent`, `subagent_status` and `send_subagent_message`. Each run belongs
to the user and Session that started it. Switching Sessions does not redirect an
existing run's result. Children receive a self-contained task and later
corrections, with independent model history.

`spawn_subagent(agent, task)` accepts only the registered agent name and its task.
Include relevant data, constraints and the expected deliverable in `task`; the
parent's history is not inherited. The application configures each agent's system
instructions, provider and tools in its registry factory. These are not spawn
parameters. A general-purpose agent can take its role from the task; register a
specialist when you need reusable instructions or a different model or tool set.
Every factory must return a new Agent instance.

PHP callers use `$subagents->spawn($session, $agentName, $task)`. Callers that
previously passed a separate `context` argument must include that content in
`task` instead. Existing stored runs with `context` remain readable: their context
is appended to the task when loaded.

The module stores runs in the existing `StorageInterface`; it does not need a
second persistence implementation. Configure the same durable storage and Symfony
Lock backend in every process. In-memory storage and locks are suitable only for
single-process tests.

```php
use NeuronInteraction\Subagents\Registry;
use NeuronInteraction\Subagents\SubagentStore;
use NeuronInteraction\Subagents\Subagents;
use NeuronInteraction\Subagents\Adapter\ConsoleAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;

// $storage, $session and $agent are configured as in the quick start.
// $createGeneral is a Closure returning a NEW configured Neuron Agent.
$registry = (new Registry())->register('general', 'Complete a delegated task', $createGeneral);
$locks = new LockFactory(new SemaphoreStore());
$runs = new SubagentStore($storage, $locks);
$subagents = new Subagents($registry, $runs, new ConsoleAdapter(
    [PHP_BINARY, '/app/bin/subagent-worker.php'],
    '/app/var/subagents.log',
));

$conversation = (new Conversation($agent, $session))->setSubagents($subagents);

foreach ($conversation->sendInput('Delegate a comparison of A (10 EUR/month) and B (80 EUR/year) to the general agent.') as $event) {
    // Render the native stream as usual.
}

// Later, the host invokes this even without a new user message.
foreach ($conversation->processCompletions() as $event) {
    // Render the same stream, including the parent's new answer.
}
```

`setSubagents()` installs the module's lock factory for all turns on this
Conversation. Delegation tools are bound to the Session captured when each Agent
turn starts, without replacing the Agent's other tools. These tool names are
reserved when enabling the module. `Conversation` attaches them through
`SubagentToolkit`, whose native Neuron guidelines explain background execution,
automatic result delivery and avoiding status polling. Neuron adds these guidelines
to each turn without replacing the Agent's configured instructions. Reporting
preferences, including acknowledgements and combined reports, remain defined by
the user and the application's instructions or skills. No operational
`setInstructions()` block is required in the
[complete terminal example](examples/bin/11-subagents.php).

`processCompletions()` is lazy: consuming its generator executes at most one
completion. An empty or busy inbox returns no events and does not invoke the
model. A busy Session leaves its result pending for another poll. The return
value is the last `AgentState`, or `null` for no work or a recovered answer.
Application completion messages carry `subagent_event_id` metadata. They bypass
human input history, message preparation/display processors and title generation.
They remain in saved model history, explicitly labeled as application events.
Hosts can use the metadata to distinguish them in a transcript.

Completion events leave the next action to the parent, following the user's request
and the Agent's instructions. The parent may end a turn without text, for example
while gathering results for a single report. This still consumes the completion;
it does not impose a wait-for-all barrier. Whether the model chooses silence
depends on its behavior and instructions.

After saving the parent's final assistant message, the stream yields
`SubagentCompleted` with `run`, `reply` and `recovered`. The reply can have no text;
hosts should render no response in that case. When `recovered` is true, no answer
chunks are replayed: reload/upsert the saved reply by its message ID.
Acknowledgement means the turn was saved, not that a terminal or browser received it.

Results read through `subagent_status` are recorded on the parent's final saved
message (`subagent_read_result_ids`). The next completion poll acknowledges those
results without invoking the model or emitting another reply, including after a
restart. A failed turn, a running-status check or a direct application status read
does not consume a result. This prevents duplicate notifications; it does not
force the model to wait for results it has not yet read.

### Terminal execution

Install `symfony/process` and `symfony/console` for the console adapter and command.
`ConsoleAdapter` starts a detached POSIX process. Its entry point registers
`Subagents\Console\WorkCommand` with an `Executor` built from the same registry,
shared storage and lock backend. It receives `subagents:work RUN_ID`; no container
or PHP file returning a service is required.

The [terminal example](examples/bin/11-subagents.php) and
[worker entry point](examples/bin/subagent-worker.php) show the full wiring:

```bash
cd examples
composer install
composer subagents
# Reopen the Session printed by the first invocation:
composer subagents -- SESSION_KEY
```

The terminal remains alive, queues input independently of turn execution and calls
`processCompletions()` periodically. It starts child processes automatically;
Messenger and Mercure are not required. After closing the terminal, completed
results stay pending until the original Session is reopened. Console failures
are logged and can be inspected with `subagent_status`; automatic child retry is
not provided by the console adapter.

### Messenger and Mercure

Install `symfony/messenger` and the host application's transport package. Use
`MessengerAdapter` instead of `ConsoleAdapter`, register `ExecuteSubagentHandler`
with an `Executor`, and configure an **asynchronous** `subagents` transport.
Use a separate `parents` transport for `ProcessCompletions` messages:

```yaml
framework:
  messenger:
    failure_transport: failed
    transports:
      subagents:
        dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        options: { queue_name: subagents }
      parents:
        dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        options: { queue_name: parents }
      failed:
        dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        options: { queue_name: failed }
    routing:
      'NeuronInteraction\Subagents\Adapter\ExecuteSubagent': subagents
      'NeuronInteraction\Subagents\Adapter\ProcessCompletions': parents
```

This queue configuration targets a transport supporting `queue_name`, such as
Doctrine. Configure retries and redelivery timeouts for your maximum task duration.
Both adapters also attach `TransportNamesStamp` with the configured transport name.

Compose these objects in your application's service configuration:

```php
use NeuronInteraction\Subagents\Executor;
use NeuronInteraction\Subagents\Adapter\ExecuteSubagentHandler;
use NeuronInteraction\Subagents\Adapter\MessengerAdapter;
use NeuronInteraction\Subagents\Adapter\MessengerCompletionRelay;
use NeuronInteraction\Subagents\Adapter\ProcessCompletionsHandler;
use NeuronInteraction\Subagents\Console\RelayCommand;

$subagents = new Subagents($registry, $runs, new MessengerAdapter($bus));
$createConversation = static function (string $userId, string $sessionKey) use (
    $storage, $messageStore, $subagents, $createParent,
): Conversation {
    $sessions = new SessionStore($storage, $messageStore, $userId);
    $session = $sessions->get($sessionKey)
        ?? throw new OutOfBoundsException('Unknown session for this user');
    $conversation = new Conversation($createParent($userId, $sessionKey), $session);
    $conversation->setSubagents($subagents);
    return $conversation;
};
$childHandler = new ExecuteSubagentHandler(new Executor($registry, $runs));
$parentHandler = new ProcessCompletionsHandler($createConversation);
$relay = new MessengerCompletionRelay($runs, $bus);
$relayCommand = new RelayCommand($relay);
```

Register the handlers on the bus and the command in your host's console.
The closure looks up the user-owned Session and creates a fresh Conversation.
Use it for both user requests and automatic turns. Queue messages carry trusted user/session IDs;
never accept them as authorization claims from a public client.

`$createParent` receives `(string $userId, string $sessionKey)` and returns a fresh
configured Agent. To publish responses through Mercure, configure that Agent:

```php
$agent->setStreamAdapter(static fn () => new AgentChunkAdapter())
    ->setChannel(static fn () => new MercureChannel(
        hub: $hub,
        topic: $authorizedTopic,
        private: true,
    ));
```

Here `AgentChunkAdapter` is `NeuronAI\Agent\Adapters\AgentChunkAdapter`, and
`MercureChannel` is `NeuronAI\Workflow\Streaming\Channel\MercureChannel`.
Derive the topic from both user and Session, and authorize subscribers in the host.
The handler consumes the generator, which also delivers Neuron events to the
configured channel. Interaction-specific events such as `SubagentCompleted` are
not automatically published by that channel; a frontend reloads saved history
for recovery/reconnection, or the host forwards those events itself.

Run Messenger consumers and the relay under supervision:

```bash
php bin/console messenger:consume subagents parents
php bin/console subagents:relay --watch --interval=5
```

Use multiple consumers if the parent must respond while another worker runs a
child. The periodic relay rescans pending Sessions, recovering missed wakeups and
busy turns. Duplicate wakeups are harmless. A relay command without `--watch`
performs one pass and can be scheduled externally. Mercure transports output;
it does not schedule turns.

### Coordination and recovery

Symfony Lock is a library dependency; Symfony Framework is not required.
No lock/lease methods have been added to `StorageInterface`.
Execution requires a **non-expiring** Symfony lock backend:

- `SemaphoreStore` uses `ext-sysvsem` for processes sharing one host/IPC namespace.
- `PostgreSqlStore` uses PostgreSQL advisory locks for processes on multiple hosts.
  Use a dedicated connection that retains its session for the lock lifetime.
- `InMemoryStore` is suitable for single-process tests only.

Expiring backends, including RedisStore and PdoStore, are rejected at acquisition.
A tool/provider may block longer than a TTL without yielding a renewal checkpoint;
this implementation does not claim that refreshing between chunks solves that.
Every process must use the same backend and resource naming. Shared locks do not
replace shared storage: multi-host deployments need shared run and message stores.

A Session lock spans model execution, saved response and completion acknowledgement.
Human turns use the same lock and throw `ExecutionBusyException` when occupied. Locks are
acquired on stream consumption and released in `finally`; finish consuming or
release abandoned generators. Commands run their synchronous state changes under
the selected Session's lock. Direct Session/history operations outside Conversation
remain the host's responsibility to coordinate with the same resource:
`neuron-interaction.session.` plus `Session::messageNamespace()`.

Each child has its own execution lock. A separate short state lock serializes
corrections with finalization, so an accepted correction arriving during the final
inference causes another inference instead of being lost. Acceptance only means
the correction was stored. Failed/completed runs reject further corrections.

Final parent messages, including silent completions, carry the run identity in
the same message-store append as the message. If acknowledgement fails afterward, retry finds the saved answer and
acknowledges without invoking the model again. Recovery notes are not tagged as
successful answers. Failures before a final saved answer may repeat model/tool
work and already delivered live chunks. Durable message storage must preserve
message IDs and metadata; this is not a general exactly-once guarantee for tools.
Failed children remain inspectable, and Messenger applies its retry policy.
Only completed children enter the parent's completion inbox.

Interactive approval/resume of a child or automatic turn requires host integration.
This feature does not change StopSignal or cancel descendants when stopping a
parent response. Automatic turns do not clear a human StopSignal. If using stop
callbacks, create a separate provider/client and execution-specific signal for
each execution; a shared callback can otherwise affect the wrong turn.
Deleted Sessions cannot receive automatic replies; hosts must retire associated
pending work when implementing deletion. The file store scans runs for pending
work; it does not add a persistent queue index or retention policy.

## Storage and web integration

Use StorageInterface for Session records, preferences, input history and stop
signals. Supply a Neuron MessageStoreInterface separately for messages. FileStorage
writes one complete JSON file per entry; InMemoryStorage keeps the same contract
in one process. SessionStore, ConfigurationStore and InputHistory receive the
authenticated user ID.

SessionStore takes dependencies in this order: StorageInterface,
MessageStoreInterface, user ID. Session binds the Agent directly to the supplied
Neuron message store. The Agent thread ID includes the owning user and Session
identity, rather than the bare Session key. Neuron handles message persistence,
archiving and duplicate IDs.

After a Conversation turn returns successfully, Conversation updates lastUsedAt
and sets hasContent to true. Failed or abandoned turns do not update these fields,
even if Neuron saved messages before a later failure. Direct Agent or ChatHistory
use persists messages without updating the Session record. The /clear Command
starts a new empty Session and preserves the previous one.

```php
use NeuronAI\Chat\History\FileMessageStore;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;

$storage = new FileStorage(__DIR__ . '/interaction-state');
$userId = 'local-user'; // Use the authenticated identity in your application.
$messageStore = new FileMessageStore(__DIR__ . '/interaction-state/messages');
$sessions = new SessionStore($storage, $messageStore, $userId);
$settings = new ConfigurationStore($storage, $userId);
$inputs = new InputHistory($storage, $userId);
```

Implement `StorageInterface` for another persistence backend:

| Method | Contract |
| --- | --- |
| `create($namespace, $value, $key = null)` | Insert without replacement and return the supplied or generated opaque string key. An existing supplied key throws `DuplicateKeyException`. |
| `get($namespace, $key)` | Return the array value or `null` when absent. Missing reads create no storage state. |
| `set($namespace, $key, $value)` | Insert or replace the complete value. Replacement preserves insertion position. |
| `update($namespace, $key, $assignments = [], $removals = [])` | Atomically assign or remove selected fields. Returns `false` when the entry is absent; `true` when it exists, including empty or unchanged updates. |
| `delete($namespace, $key)` | Remove the entry if present. Recreating it gives it a new insertion position. |
| `list($namespace, $limit = null)` | Return key/value pairs oldest insertion first. A positive limit selects the latest entries in that order; zero returns none and negatives are invalid. |
| `clear($namespace)` | Remove all entries in that namespace, leaving other namespaces intact. |

Values are JSON-compatible PHP arrays containing arrays, scalars and nulls;
objects, recursive arrays, invalid UTF-8 and non-finite numbers are rejected.
Stored values and returned snapshots are detached from caller references. Namespace
and key identifiers start with a letter or digit, followed by letters, digits,
dots, underscores or hyphens. Numeric-looking keys remain strings in list results.
Storage has no document metadata, metadata filters or message-specific operations.

Field paths contain literal string segments. A dot in a segment is part of the
name. Assigning null retains the field; removals delete it. For example:

```php
$key = $storage->create('application', ['metadata' => ['project' => 'alpha', 'obsolete' => true]]);
$storage->update('application', $key, [
    ['path' => ['metadata', 'project'], 'value' => 'beta'],
    ['path' => ['metadata', 'note'], 'value' => null],
], removals: [['metadata', 'obsolete']]);
```

Missing intermediate objects can be created by assignments. Traversing a scalar
or indexed list fails; replace a list as a whole instead. Empty paths and overlapping
paths are rejected, and any invalid update leaves the entire entry unchanged.
An empty update is a no-op on an existing entry. Delete and clear are idempotent.

Both adapters guarantee atomic individual operations, including supplied-key
creation and nested updates. FileStorage coordinates namespace mutations and readers,
publishes complete values through atomic file replacement and preserves ordering
when reopened. A write changes the affected entry, rather than rewriting a growing
history. Listing scans and sorts the namespace: a result limit does not guarantee
bounded filesystem work. There is no persistent query index, JSONL log or compaction.
More entries also mean more files to read; this design makes no throughput claim.

These guarantees do not extend to transactions across entries or namespaces.
Message insertion and Session-use registration, Session deletion and batch
archiving are multi-operation workflows, and failures propagate. The Host
Application serializes overlapping turns, archive, clear and delete for one Session.
Independent ChatHistory instances do not coordinate a conversation automatically.

Session message persistence also changes: SessionMessageStore has been removed.
Session uses the supplied Neuron message store directly, without a decorator.
Provide a native InMemoryMessageStore, FileMessageStore or SQLMessageStore.
Existing per-message Storage entries are not read or automatically migrated into
the supplied Neuron store; configure a fresh state root when adopting this change.
FileMessageStore replaces the thread's complete message file on each append;
use a suitable database message store for concurrent workers.

The Storage API and file format are breaking changes. Existing documents are not
migrated or automatically backfilled. Use a fresh root for new state; see the
[pending Storage release notes](docs/release-notes/storage-redesign.md) for API changes.

For each web request, authenticate the user, build their SessionStore, retrieve
or create the authorized Session, configure the Agent, and construct Conversation.
Restore commands, preferences and processors before submitting input. Serialize
stream objects into your chosen HTTP protocol; PHP objects and EventInterface
are not themselves a wire format. Return the session key to the client so later
requests can reopen it. Choices return as command identifiers and values.

Coordinate writes when several requests or clients can operate on the same
session or shared state. Conversation provides no pending-input queue, and
storage does not provide transactions across a turn.
Without a configured lock factory, the host must serialize turns itself.
Enabling subagents configures shared Symfony locks; alternatively pass
`lockFactory:` to the Conversation constructor. Queue pending messages in the client or application layer and choose how errors,
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
