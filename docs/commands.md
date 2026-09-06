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

## Session selection

`/resume` without arguments emits a `SelectionRequest` and returns. The Adapter
presents its options and invokes the request's target Command again with the
chosen value in new `CommandArguments`. `/clear` installs a distinct empty
Session History while preserving the previous Session. Agent prompting,
presentation and the interaction lifecycle remain Adapter responsibilities.

## Custom kits

Custom kits extend `AbstractCommandKit<TCommand>` and provide their members.
Adapters may use this shared filtering behavior for their own Command types;
the shared `Commands` dispatcher accepts only `CommandInterface` members.

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

## Help and Leave

Mount `NeuronInteraction\Command\HelpCommand` and
`NeuronInteraction\Command\LeaveCommand` explicitly, like Session Commands.
Both implement `CommandInterface` and use `CommandAdapterInterface`. Help lists
the mounted Commands and descriptions through the Adapter; Leave calls `stop()`.
The Adapter defines the stop effect. Neither Command depends on a terminal,
and the shared dispatcher imposes no concurrency policy. Both accept a
configured identifier in their constructor.

## Backend Adapter

[BackendAdapter](../examples/BackendAdapter.php) implements every operation of
`CommandAdapterInterface`. It admits its Commands and collects notices, warnings,
a `SelectionRequest`, and the stop effect for one response. Its `afterExecution()`
returns response data containing those values, the technical status, identifier,
and any error message. The caller obtains that response directly from `run()`.
The example delegates `promptAgent()` to a callback supplied by the Host
Application. Agent execution, scheduling and response streaming are outside this
package. No model request is made by these examples.

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
permission to run them during a Turn and Picker presentation belong to Neuron TUI.
