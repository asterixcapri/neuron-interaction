# Neuron Interaction

Neuron Interaction is a small PHP library for building reusable interaction
flows around [Neuron AI](https://github.com/neuron-core/neuron-ai) Agents. It
provides the state and application-level behavior common to conversational
interfaces while leaving presentation, Agent execution and response streaming
to the Host Application.

The same interaction logic can therefore serve a terminal, web backend or
another delivery mechanism. Each Host Application supplies an Adapter that
translates the library's operations into its own UI and response model.

## What it provides

- **SessionStore** persists Neuron AI chat Histories and make conversations
  discoverable, resumable and replaceable without discarding earlier ones.
- **Input history** records original submissions across Sessions and optionally
  provides shell-style recall navigation with independent cursor state per UI.
- **Commands** provide presentation-independent dispatch, admission and
  completion, together with reusable `/clear`, `/resume`, `/help` and `/exit`
  behavior.
- **Selections** describe choices as serializable values, labels and
  descriptions, so multi-step interactions can cross backend request boundaries.
- **Storage** offers in-memory and JSON-file implementations behind a small
  interface that applications can replace with their own persistence.

## Design strengths

- **Presentation independence.** Commands express interaction effects through
  an Adapter instead of printing to a terminal or returning a fixed HTTP shape.
- **Explicit application ownership.** The Host Application controls the active
  Agent, turn execution, authorization, scheduling, streaming and lifecycle.
- **Composable modules.** SessionStore, Commands, Input history and Storage can be
  adopted together or independently; no application facade is required.
- **Native Neuron AI integration.** Persisted Sessions expose Neuron AI's own
  `ChatHistoryInterface`, so they can be installed directly on an Agent.
- **Request-safe workflows.** Selection state is carried as data and resumed by
  a later Command invocation; an Adapter does not need to survive between
  requests.
- **Replaceable boundaries.** Both presentation and persistence sit behind
  focused interfaces, keeping framework and infrastructure choices outside the
  shared interaction model.

## SessionStore and Storage

```php
use NeuronAI\Agent\Agent;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;

$storage = new FileStorage(__DIR__ . '/interaction-state');
$sessionStore = new SessionStore($storage, 'local-user');
$agent = new Agent();
$agent->setChatHistory($sessionStore->create());

// After the Agent has exchanged messages, list recognizable Sessions.
foreach ($sessionStore->summaries() as $session) {
    // Render $session->title according to your Adapter's rules.
    // Resume a chosen Session by installing its History on the Agent:
    // $history = $sessionStore->read($session->key);
    // if ($history !== null) { $agent->setChatHistory($history); }
}
```

Host Applications explicitly install a History from `create()` or `read($key)`
on the Agent when they want that conversation managed by this SessionStore.
SessionStore does not import arbitrary Agent Histories or automatically select the
latest conversation. Only Histories managed through this Store appear in
its listing, subject to the existing title rules.

Use `InMemoryStorage` for transient state, or implement `StorageInterface`
for application-specific persistence. Storage holds namespaced JSON documents
identified by logical keys. It preserves string metadata together with data;
`StoredDocument::size()` reports the JSON size of its data.

`SessionStore::create()` creates a distinct empty History. `SessionStore::summaries()`
returns Sessions with user-authored text, ordered by most recent use and then
key. Titles preserve the first non-blank user-authored textual content without
terminal placeholders, escaping or truncation. `SessionStore::read($key)`
reopens its stored History or returns null for absent or other-user keys.
`SessionStore::delete($key)` deletes only the current user’s Session and is a
no-op when absent. Sessions expose `getKey()` and `getUserId()`; History updates
persist automatically. Supply a stable local or authenticated identity when
constructing the Store. Ownerless documents are never assigned implicitly.

Application metadata use camelCase names and string values. Pass initial values
when creating a Session, then update individual values; these changes persist
immediately and preserve its messages. Application fields named `userId` or
`lastUsedAt` remain ordinary metadata and cannot change ownership or ordering.

```php
$session = $sessionStore->create(['projectId' => 'alpha', 'branchName' => 'main']);
$session->setMetadata('branchName', 'release');
$metadata = $session->getMetadata(); // The complete application metadata map.
$session->removeMetadata('branchName');
$matches = $sessionStore->summaries(['projectId' => 'alpha']);
```

Multiple filters are combined with AND using exact string equality. Missing
keys do not match; extra metadata are ignored. Results always belong to the
Store's user and retain the same title, empty-conversation and ordering rules.
Metadata edits preserve the last History-use time; adding or clearing messages
updates it and retains application metadata.

## Input history

```php
use NeuronInteraction\InputHistory\InputHistory;

$inputs = new InputHistory($storage);
$inputs->record('/resume session-key');
$inputs->record('A message exactly as submitted');
$submitted = $inputs->entries(); // Oldest first, across all Sessions.
```

Adapters record original submissions, including their Command invocation
syntax, and exclude prompts generated by Commands. Blank inputs are ignored;
only consecutive exact duplicates collapse. Each read and append uses the
current Storage sequence, so existing instances see one another's submissions.
Simultaneous writes are not coordinated by this module; Adapters must serialize
them when sharing Storage across concurrent writers.

The same `InputHistory` instance optionally provides recall navigation:

```php
$recalled = $inputs->older('Unsubmitted draft');
$newer = $inputs->newer(); // Restores the draft past the newest input.
$navigating = $inputs->isNavigating();
$inputs->leave(); // Discards the navigation position and saved draft.
```

Each instance keeps its own position and draft in memory; only the input
sequence is persisted and shared. Adapters own keyboard handling and decide
when to leave navigation, such as on editing or submitting input. A web
frontend can use only `entries()` and navigate locally in JavaScript, without
a backend call for each arrow key.

No Neuron TUI dependency, legacy reader, format fallback or automatic
migration is supplied. Existing legacy files are left untouched.

## Commands

Commands let users perform actions such as starting a new conversation or
reopening a saved one. The library includes:

| Command | What it does |
| --- | --- |
| `/clear` | Start an empty Session, keeping the previous conversation. |
| `/resume` | Choose a saved conversation, or reopen one by its key. |
| `/help` | List the available Commands. |
| `/exit` | Ask the application to end the interaction. |

Choose which Commands your application offers. `SessionCommandKit` groups
Clear and Resume:

```php
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\SessionCommandKit;

$commands = new Commands([
    new SessionCommandKit(),
    new HelpCommand(),
    new LeaveCommand(),
]);

// $adapter connects the Commands to your application.
$output = $commands->run('/resume', new CommandArguments(), $adapter);
```

The Adapter decides how to display messages, offer choices and end the
interaction. A terminal Adapter can update the screen; a backend Adapter can
return response data. The Commands work with either.

To reopen a known Session, pass its key as the arguments:

```php
$output = $commands->run('/resume', new CommandArguments($sessionKey), $adapter);
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

    public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
    {
        $adapter->say('Hello!');
    }
}

$commands->addCommand(new HelloCommand());
```

See the [Command and Adapter reference](docs/commands.md) for custom Adapters,
kits, execution outcomes and error handling.

## Backend examples

These examples use [BackendAdapter](examples/BackendAdapter.php) to collect
Command messages and choices into response data. They run locally without an
HTTP server, API credentials or model requests.

Each backend example is self-contained and demonstrates one flow:

| Example | What it shows |
| --- | --- |
| [help.php](examples/help.php) | Execute Help and print the response. |
| [exit.php](examples/exit.php) | Return the stop effect to the Host Application. |
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

## Development

```bash
composer install
composer test
composer stan
```
