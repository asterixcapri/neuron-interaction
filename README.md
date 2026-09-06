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
$sessions = new SessionStore($storage, 'local-user');
$agent = new Agent();
$agent->setChatHistory($sessions->create());

// After the Agent has exchanged messages, list recognizable Sessions.
foreach ($sessions->summaries() as $session) {
    // Render $session->title according to your Adapter's rules.
    // Resume a chosen Session by installing its History on the Agent:
    // $history = $sessions->read($session->key);
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
$session = $sessions->create(['projectId' => 'alpha', 'branchName' => 'main']);
$session->setMetadata('branchName', 'release');
$metadata = $session->getMetadata(); // The complete application metadata map.
$session->removeMetadata('branchName');
$matches = $sessions->summaries(['projectId' => 'alpha']);
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

```php
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\SessionCommandKit;

$commands = new Commands(new SessionCommandKit());
// $adapter implements CommandAdapterInterface for this invocation.
$output = $commands->run('/resume', new CommandArguments(), $adapter);
```

Every mounted identifier includes its leading slash, including aliases. Names
without a slash are rejected immediately; lookup is exact, with no case or
prefix normalization. This revision supersedes the historical extraction
requirement for neutral identifiers. Backend Adapters use the same identifiers.

Commands receive `CommandAdapterInterface`, use its nine shared verbs and return
`void`. `Commands::run()` owns the whole invocation: it resolves the first matching
Command, requests Adapter admission, invokes the Command, and passes its technical
`CommandExecution` outcome to `afterExecution()`. Callers receive the Adapter's
output from this one call; they do not coordinate completion separately.

The collection preserves mounting order and executes the first duplicate
identifier. Its constructor also accepts individual Commands or arrays mixing
Commands and kits. Kits support immutable `only()` and `exclude()` filters by class.

- An unknown identifier reaches `afterExecution()` with an `unknown` outcome,
  without admission or Command dispatch.
- `admit()` receives the resolved Command. Returning `false` skips dispatch and
  completion, and `run()` returns `null`. The Adapter handles visible refusal.
- An admitted Command produces `completed` when it returns or `failed` with its
  original exception when it throws. Both reach `afterExecution()`.
- Exceptions from `admit()` or `afterExecution()` propagate to the caller.
  Completion is never retried as a failed Command invocation.

`afterExecution()` defines the Adapter's output: a backend can return its response
data or a framework response, while a terminal Adapter may perform presentation
and return `null`. `CommandAdapterInterface<TOutput>` and the generic `run()`
method preserve that output type in static analysis; `run()` returns
`TOutput|null` because admission can refuse. No response format or transport
dependency is imposed by the shared package.

`CommandExecution` is a technical outcome passed to the Adapter, not a domain
result. `completed` means the invocation returned; a requested selection or Agent
response may still be pending. Command failures do not roll back effects already
performed, including notices, History changes, or immediate Agent replacement.

This is an intentional shared-contract migration: replace
`CommandControlsInterface` with `CommandAdapterInterface` in Commands and Adapter
implementations, add `admit()` and `afterExecution()`, and consume the Adapter's
output from `run()` instead of expecting `CommandExecution`. Commands can annotate
their parameter as `CommandAdapterInterface<mixed>`; concrete Adapters declare
`@implements CommandAdapterInterface<TheirOutputType>`.

`/resume` without arguments emits a `SelectionRequest` and returns. The Adapter
presents its options and invokes the request's target Command again with the
chosen value in new `CommandArguments`. `/clear` installs a distinct empty
Session History while preserving the previous Session. Agent prompting,
presentation and the interaction lifecycle remain Adapter responsibilities.

Custom kits extend `AbstractCommandKit<TCommand>` and provide their members.
Adapters may use this shared filtering behavior for their own Command types;
the shared `Commands` dispatcher accepts only `CommandInterface` members.

## Backend Adapter example

[BackendAdapter](examples/BackendAdapter.php) implements every operation of
`CommandAdapterInterface`. It admits its Commands and collects notices, warnings,
a `SelectionRequest`, and the stop effect for one response. Its `afterExecution()`
returns response data containing those values, the technical status, identifier,
and any error message. The caller obtains that response directly from `run()`.
The example delegates `promptAgent()` to a callback supplied by the Host
Application. Agent execution, scheduling and response streaming are outside this
package. No model request is made by these examples.

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

These scripts simulate backend requests without an HTTP server or model calls.
Each supplies an empty prompt callback because these Commands do not prompt the
Agent. The seeded conversations make the examples runnable without setup.

In the selection example, the first response contains `selection.options` for a
frontend to display. Each option has a `value` (the Session key), `label` and
`description`. The script simulates choosing one conversation and submitting its
key with `/resume` to a fresh Agent and Adapter. Cancelling means making no second
request. The examples share `InMemoryStorage` within one process; separate backend
requests can construct `FileStorage` with the same root and a SessionStore scoped
to the authenticated user.
The Host Application supplies its configured Agent and restores the active
Session when appropriate. Original submitted input is recorded at the Adapter
boundary; generated prompts and the internal selection continuation are not
additional typed submissions.

Commands, SessionStore, Input history and Storage are composed directly. There is
no required application facade, HTTP framework, authentication subsystem,
worker topology or subagent orchestration. Help and Leave are shared Commands;
permission to run them during a Turn and Picker presentation belong to Neuron TUI.

## Development

```bash
composer install
composer test
composer stan
```

## Help and Leave

Mount `NeuronInteraction\Command\HelpCommand` and
`NeuronInteraction\Command\LeaveCommand` explicitly, like Session Commands.
Both implement `CommandInterface` and use `CommandAdapterInterface`. Help lists
the mounted Commands and descriptions through the Adapter; Leave calls `stop()`.
The Adapter defines the stop effect. Neither Command depends on a terminal,
and the shared dispatcher imposes no concurrency policy. Both accept a
configured identifier in their constructor.

## Mounting Commands

`Commands::addCommand()` mutates the collection and returns that same instance:

```php
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\SessionCommandKit;

$commands = (new Commands())
    ->addCommand(new HelpCommand())
    ->addCommand([new SessionCommandKit(), new LeaveCommand()]);
```

Constructor mounting and incremental mounting accept individual Commands, kits
and mixed arrays, preserve order, and reject invalid members or identifiers
immediately. The first matching duplicate receives dispatch. Configure Commands
before running an Adapter; live reconfiguration is outside this contract.
