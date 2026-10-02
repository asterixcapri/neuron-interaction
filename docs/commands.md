# Command and Adapter reference

For an introduction and runnable examples, see [Commands in the README](../README.md#commands).
This reference describes the execution contract for custom Commands and Adapters.

## Identifiers and execution

Every mounted identifier includes its leading slash, including aliases. Names
without a slash are rejected immediately; lookup is exact, with no case or
prefix normalization. Backend Adapters use the same identifiers.

Commands receive `CommandAdapterInterface`, use its shared operations and return
`void`. `Commands::run()` owns the whole invocation: it resolves the first matching
Command, requests Adapter admission, invokes the Command, and passes its technical
`CommandExecution` outcome to `afterExecution()`. Callers receive the Adapter's
output from this one call; they do not coordinate completion separately.

## Admission and completion

- An unknown identifier reaches `afterExecution()` with an `unknown` outcome,
  without admission or Command dispatch.
- `admit()` receives the resolved Command. Returning `false` skips dispatch and
  completion, and `run()` returns `null`. The Adapter handles visible refusal.
- An admitted Command produces `completed` when it returns or `failed` with its
  original exception when it throws. Both reach `afterExecution()`.
- Exceptions from `admit()` or `afterExecution()` propagate to the caller.
  Completion is never retried as a failed Command invocation.

## Adapter output

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

## Migrating from CommandControlsInterface

Replace `CommandControlsInterface` with `CommandAdapterInterface` in Commands and Adapter
implementations, add `admit()` and `afterExecution()`, and consume the Adapter's
output from `run()` instead of expecting `CommandExecution`. Commands can annotate
their parameter as `CommandAdapterInterface<mixed>`; concrete Adapters declare
`@implements CommandAdapterInterface<TheirOutputType>`.

## Optional shared Conversation delegation

Commands continue to depend on `CommandAdapterInterface<TOutput>`. An Adapter may
extend `AbstractCommandAdapter<TOutput>` to share `agent()`, `useAgent()`,
`session()`, `useSession()` and `sessionStore()` through a protected Conversation.
It can also implement the interface directly. Subclasses document their output
with `@extends AbstractCommandAdapter<TheirOutputType>`.

The Conversation is not exposed through a public accessor. `promptAgent()` remains
a frontend operation: terminal Adapters enqueue the prompt through their scheduler,
while backend Adapters submit it through the host's configured flow. Session
selection overrides must preserve any frontend History invalidation.

## Session selection

`/resume` without arguments emits a `Selection` and returns. The Adapter
presents its options and invokes the request's target Command again with the
chosen value as a string. `/clear` installs a distinct empty
conversation while preserving the previous Session. Both Commands call
`adapter->useSession($session)`. Adapters synchronize
their presentation with the Agent; Commands do not request a view refresh. Agent prompting,
presentation and the interaction lifecycle remain Adapter responsibilities.

## Mounting Commands

`Commands::addCommand()` mutates the collection and returns that same instance:

```php
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\ResumeCommand;

$commands = (new Commands())
    ->addCommand(new HelpCommand())
    ->addCommand([new ClearCommand(), new ResumeCommand(), new LeaveCommand()]);
```

Create an empty collection with `new Commands()`. `addCommand()` accepts an
individual Command or an array of Commands, preserves order, and rejects invalid
members or identifiers immediately. The first matching duplicate receives dispatch. Configure Commands
before running an Adapter; live reconfiguration is outside this contract.

## Help and Leave

Mount `NeuronInteraction\Command\HelpCommand` and
`NeuronInteraction\Command\LeaveCommand` explicitly, like Session Commands.
Both implement `CommandInterface` and use `CommandAdapterInterface`. Help lists
the mounted Commands and descriptions through the Adapter; Leave calls `stop()`.
The Adapter defines the stop effect. Neither Command depends on a terminal,
and the shared dispatcher imposes no concurrency policy. Both accept a
configured identifier in their constructor.

## Messages to the person

Commands use `notify($text)` for information, `warn($text)` for warnings, and
`error($text)` for expected failures. The Adapter chooses how to present each
kind of message. For example, Resume reports an unknown Session key with
`error()`, while an empty collection of earlier Sessions uses `warn()`.

These operations communicate only: they do not interrupt the Command or change
its technical `CommandExecution` status. A Command that cannot continue must
return explicitly. Exceptions still produce the existing failed execution.

Adapter implementations must replace `say()` with `notify()` and implement
`error()` separately from `warn()`; there is no compatibility alias for `say()`.

## Backend Adapter

[BackendAdapter](../examples/BackendAdapter.php) implements every operation of
`CommandAdapterInterface`. It admits its Commands and collects notices, warnings, expected errors,
a `Selection`, and the stop effect for one response. Its `afterExecution()`
returns response data containing those values, the technical status, identifier,
and any exception message. The `errors` list contains expected failures reported
by Commands; the singular `error` field contains the execution exception message.
The caller obtains that response directly from `run()`.
`promptAgent(UserMessage $prompt)` submits the complete Neuron user message,
including content blocks and metadata. Commands that only generate text wrap it
in `new UserMessage($text)`. Adapters must retain attachments when scheduling
or executing the message.

The example delegates `promptAgent()` to a callback supplied by the Host
Application, with the Agent and complete UserMessage as arguments. Scheduling and response presentation belong to the client;
Conversation can execute the prompt through submitMessage(). No model request is made by these examples.

In [resume-selection.php](../examples/resume-selection.php), the first response contains `selection.options` for a
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
the client decides whether to admit them during a Turn and presents the Picker.

## Commands during Agent work

`ConcurrentCommandInterface` extends `CommandInterface` without adding methods.
Implement it when a Command can execute while the Agent is working without
interfering with state used by that work. Help and Leave implement this marker.
Neuron TUI uses this marker to keep these Commands visible and admit them during
a busy Turn. Other clients choose their own presentation and admission policy
through `CommandAdapterInterface::admit()`. The marker grants no backend permission
and does not enforce restricted controls.

## Replacing the Agent

Implement `useAgent(Agent $agent): void` and `useSession(Session $session): void`
in custom Adapters. Keep the current Session explicitly alongside the current
Agent. Expose it through `session(): Session`, which always returns the selected
conversation. `useAgent()` binds the replacement to that Session through `bindTo()`;
`useSession()` binds the current Agent to the selected Session. Retain the returned
Agent copy in both cases. Validate selected Sessions through the Adapter's
SessionStore before changing state, so absent and other-user keys cannot be used.

The example BackendAdapter creates an empty Session on startup unless the Host
passes `session: $session` together with its matching Store. An Agent with existing
messages requires an explicit Session, which determines the active conversation.
Across backend requests, pass that Session again to continue it.

Retrieve the current Agent through `adapter->agent()` after a Command, since
binding can replace the instance. Neuron TUI exposes the same current instance
through `Tui::agent()`.

Clients mount their own collections and decide which Commands to show and admit.
Neuron TUI hides ordinary Commands during a Turn and refuses them if typed or
selected directly. Its busy state includes a local turn reservation before core
stream consumption. Exact identifiers and the first mounted duplicate win.

```php
$commands = (new Commands())->addCommand([new ExitCommand(), new ClearCommand()]);
$commands->run('/clear', '', $adapter);
```

Commands::run() resolves the identifier and asks the client Adapter to admit the
Command before dispatch. A refused invocation neither dispatches the Command nor
calls afterExecution(). Unknown, completed and failed invocations retain their
usual completion status. Selection continuations invoke Commands::run() through a
fresh client Adapter, which checks the current interaction state again.

UI commands operate on the frontend Adapter; Session and Agent changes delegate
to the core. Command prompts enter the client queue before core execution,
with preparation performed when each prompt is submitted. Conversation has no Command dispatch
or availability methods, and exposes no busy state. Selected Sessions must belong
to its SessionStore; execution coordination belongs to the caller. A web backend
also supplies application-specific authorization and shared concurrency controls.
