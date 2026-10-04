# Commands and unified input

Configure `Conversation` with `setCommands($commands)` and consume
`sendInput(string|UserMessage|CommandInput)`. No commands are registered by
default. Build a registry with `new Commands($first, $second)` or add Commands
later with `addCommand($first, $second)`. A later Command with the same name
replaces the earlier one in its original position. Invalid identifiers are rejected.
Identifiers start with slash followed by letters, digits,
underscores or hyphens; lookup is exact. `all()` and `named()` provide consultation.
setCommands() replaces the entire registry. Changes to the supplied registry
are visible to Conversation, and dispatch uses the registry when the stream is
consumed. A Command already executing continues normally.

Strings matching slash syntax dispatch a Command; other nonblank strings become
UserMessage. An explicit UserMessage is always a message, even with slash text.
CommandInput carries identifier and opaque value, defaulting to an empty string.
A slash in its value stays an argument. Commands validate their own arguments.
Blank strings execute nothing; explicit empty messages retain normal validation.

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Conversation;

final class ExplainCommand implements CommandInterface
{
    public function name(): string { return '/explain'; }
    public function describe(): string { return 'Explain a topic.'; }
    public function run(CommandContext $context, string $value): void
    {
        $context->notify('Starting explanation.');
        $context->promptAgent(new UserMessage('Explain ' . $value));
        $context->notify('Explanation completed.');
    }
}

$conversation = new Conversation($agent, $session);
$conversation->setCommands(new Commands(new ExplainCommand()));
foreach ($conversation->sendInput('/explain PHP generators') as $event) {
    // Present notifications and native Neuron events here, in stream order.
}
```

## Context and ordered effects

Every invocation receives a new concrete final CommandContext. `agent()`,
`session()`, `configurationStore()` and `commands()` expose current state and
a consultation list. Commands that need a SessionStore receive it through their
constructor, as ClearCommand and ResumeCommand do. `useSession()` binds the
supplied Session; `useAgent()` binds the selected Agent to that Session.
Both change state immediately and register SessionChanged or AgentChanged.
Explicitly call useAgent after changing an Agent property when the host needs an
AgentChanged event. The context exposes no Conversation or executable dispatcher.

`notify($text, NotificationLevel::Info)` registers human feedback. Warning and
Error use the same method. `requestSelection(SelectionRequest)`, `requestExit()`
and `promptAgent(UserMessage)` register the other effects. Commands return void
and do not yield or call Agent::stream themselves.

Requests run after the Command invocation in registration order. Notification,
prompt, notification produces the first Notification, native Agent response
objects, and the second Notification. A prompt creates no extra event or human
message preview. Multiple prompts execute sequentially; a normal return,
including response stop or approval, permits the next request. Each message is
prepared once, with attachments and metadata preserved. Ordinary input is
prepared on submission; command prompts are prepared when reached in the sequence.
Dispatch and Agent execution start only when the returned generator is consumed.
Native event objects and keys pass through; the generator returns the last
AgentState, or null when no message executed.

A Command exception preserves immediate state changes and registered requests.
Those requests execute first; then the original exception propagates. A request
exception propagates immediately and leaves remaining requests unexecuted. There
is no retry, rollback, automatic failure notification or completion event.

## Portable selection

SelectionRequest contains `command`, `prompt`, a nonempty ordered list of
SelectionOption (`value`, `label`, optional `description`) and optional request
description. It has no callback or continuation. Conversation emits it without
keeping pending selection state.

```php
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\SelectionRequest;

foreach ($conversation->sendInput('/resume') as $event) {
    if ($event instanceof SelectionRequest) {
        // The host presents label/description and retains command + option value.
    }
}
// A later submission, possibly in a new HTTP request and Conversation instance:
foreach ($conversation->sendInput(new CommandInput($command, $chosenValue)) as $event) {
    // Consume the new stream.
}
```

The host restores the correct stores and Session between HTTP requests. It need
not persist the request or PHP Conversation. Cancelling a picker submits nothing.
Values need not be among the displayed options: validation belongs to the Command.
Multiple selections are allowed; the host decides sensible presentation.

## Admission, exit and built-ins

The optional `admitCommand:` closure receives CommandInterface and returns
bool before each invocation, including selection responses. False emits a Warning
without executing the Command; a closure exception propagates unchanged. Unknown
identifiers emit Error notifications. Text is human feedback, not an outcome
protocol. Authorization, queues and busy policy belong to the host. The TUI keeps
HelpCommand and LeaveCommand available while busy and refuses ordinary Commands.

ExitRequest asks the host to leave. It does not end Conversation, stop a response,
close the process or cancel remaining requests. A web host can ignore it.
Notification, SelectionRequest, ExitRequest, SessionChanged and AgentChanged are
the five interaction events alongside native Neuron objects.

Mount built-ins explicitly: HelpCommand lists registered names/descriptions;
LeaveCommand requests exit; ClearCommand selects a new empty Session without
deleting the old one; ResumeCommand presents stored history or selects a key.
Their constructors support custom identifiers. ClearCommand and ResumeCommand
require a SessionStore as their first constructor argument. An omitted ConfigurationStore is
isolated in-memory storage per Conversation; provide persistent storage when
preferences must survive requests or processes.

## Migration

Create or retrieve a Session before constructing Conversation, and pass it as the
second argument instead of SessionStore. Conversation no longer creates an initial
Session or checks its ownership. Remove sessionStore() calls from CommandContext;
inject a SessionStore into Commands that need one. Construct the built-ins with
`new ClearCommand($sessionStore)` and `new ResumeCommand($sessionStore)`.


Replace the separate message and command execution paths with sendInput.
Pass the registry through Conversation::setCommands(), rather than the
constructor. Construct Commands variadically or extend the same registry with
addCommand(). Later registrations replace Commands with the same name. Replace host command adapters with concrete CommandContext in
run methods and stream event presentation in the host. Replace separate warning
and error operations with notify and a NotificationLevel. Replace selection
callbacks with a later CommandInput; replace stop controls with requestExit.
Completion is consumption of the stream, with AgentState|null as its return.
The previous adapter, mutable mounting, generic output and technical command
completion protocols have been removed.
