# Conversation and the frontend/backend boundary

Conversation executes messages and Commands in a Session and returns native
Neuron and interaction events in a single stream. React and Neuron TUI are frontends: they own input, pending
messages, presentation and the policy for starting the next turn.

Every Conversation requires an Agent and a SessionStore. Without an explicit
initial Session, construction creates a new Session in that Store. Use a
SessionStore backed by InMemoryStorage for process-local conversations.

History presentation follows the same boundary. Core stores and returns native
Neuron messages; each frontend chooses visible content and correlates tools for
its display. Neuron TUI projects directly into terminal entries using native
ToolCall, without core MessageEntry, ToolEntry or ToolData wrappers.

## Ordinary streaming

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Conversation;

// $agent is configured by the host; $sessionStore belongs to the current user.
$conversation = new Conversation($agent, $sessionStore, session: $session);
$stream = $conversation->submitInput(new UserMessage('Analyse this file'));
foreach ($stream as $chunk) {
    if ($chunk instanceof TextChunk) {
        echo $chunk->content;
    }
}
$state = $stream->getReturn(); // Neuron AgentState, including approval interruption.
```

There is no subscribe/executeNext pair. Preparation and rejection happen in
submitInput(), before it returns. Iteration starts the Agent; each object is
delivered as it arrives. Text, tools, reasoning and other native output retain
identity and ordering. If the host configures a Neuron stream adapter, its output
also passes through without a second conversion.

An unstarted stream does not execute the Agent. Conversation exposes no
isBusy() and does not enforce execution admission. TUI scheduling coordinates its
own turns; web applications choose their own session concurrency policy. Neuron
may impose its own execution constraints. Errors propagate unchanged, without retry.

A started generator remains suspended when iteration pauses. Breaking foreach is
not a stop request. Release an abandoned stream (`unset($stream)`) and its other
references so Neuron can release its native execution resources. An unstarted
stream can be discarded without Agent execution.

submitInput() is the only submission API. Commands register prompts through
CommandContext::promptAgent. Each message goes through the configured processors
once, with generated prompts prepared when their request is reached; processors
preserve content they recognize as already expanded. There is no public
preparation step. The frontend immediately shows original human input; when
reopening a conversation it projects saved native messages with forDisplay().
The live preview and the saved Agent History are separate representations.

## TUI script

```php
use NeuronTui\Tui;

Tui::make($agent)
    ->setSessionStore($sessionStore)
    ->run();
```

The TUI constructs its Conversation at startup, using a default in-memory
SessionStore unless `setSessionStore()` supplies one. An explicit initial Session
requires an explicit Store. Initial Session, stop signal and message processing
are configured through `setSession()`,
`setStopSignal()` and `setUserMessageProcessors()` before `run()`. The host
does not construct or retain the TUI-owned Conversation.

Internally the TUI shows original input immediately and clears the composer.
On its next tick it submits the head message, reads the native stream in an Amp
task and paints it. Input received during that task stays
in the TUI FIFO. It is prepared when the preceding task settles. No terminal
scheduler or pending queue is imposed on other core clients.

Preparation failure is shown beside the visible original message. Rejected input
returns to an empty composer; an existing newer draft is preserved and the
original remains in Input history. Provider failures and supported response
stops release the active turn and let the queue advance, without retry. Title
scheduling uses the Agent and Session captured for that execution and retains
its successful-turn eligibility.

## React and a PHP controller

The following is an integration sketch, not an HTTP application supplied by this
package. The server receives one message per POST and streams the response in
that same request. A later message can remain in React until the previous stream
settles; it has not yet been validated or accepted by the server.

```javascript
const pending = [];
let running = false;

async function submit(message) {
  showUserMessage(message); // Immediate original preview, before the HTTP request.
  pending.push(message);
  if (running) return;
  running = true;
  try {
    while (pending.length) {
      const next = pending.shift();
      try {
        const response = await fetch(`/conversations/${conversationId}/messages`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(next),
        });
        if (!response.ok) throw new Error('Message rejected');
        // Application renderer: read the SSE body progressively until it ends.
        await renderNeuronStream(response.body);
      } catch (error) {
        showError(error, next); // Preserve the rejected input for editing.
      }
    }
  } finally {
    running = false;
  }
}
```

After authentication, request validation and session admission, the controller
composes the runtime. Preparing before HTTP streaming headers lets it report
input errors as ordinary HTTP responses. Here `$emitSse` belongs to the HTTP
framework and writes and flushes frames; Neuron's own AgentChunkAdapter provides the
wire vocabulary rather than a chat-core event protocol.

```php
use NeuronAI\Agent\Adapters\AgentChunkAdapter;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Conversation;

$conversation = new Conversation(
    $agent,
    $sessionStore,
    session: $session,
    stopSignal: $stopSignal,
);
$stream = $conversation->submitInput(new UserMessage($text));
$adapter = new AgentChunkAdapter();

// Inside the framework's streaming-response callback:
try {
    foreach ($stream as $chunk) {
        $events = $chunk instanceof ProtocolEvent ? [$chunk] : $adapter->transform($chunk);
        foreach ($events as $event) {
            $emitSse($event->type, json_encode($event, JSON_THROW_ON_ERROR));
        }
    }
} catch (Throwable $error) {
    foreach ($adapter->error($error) as $event) {
        $emitSse($event->type, json_encode($event, JSON_THROW_ON_ERROR));
    }
} finally {
    unset($stream);
}
```

An application that adds stop or queue features must choose their policy. The
React sketch advances after errors; it need not copy the TUI's policy if the
product should pause or clear pending inputs instead. Page reload and cross-tab
coordination are separate features, not properties of this local queue.

## Response stop

Share one StopSignal with Neuron's StoppableHttpClient and the runtime. In a TUI
process, the TUI requests interruption only while its own task is executing.
requestInterruption() writes the signal without checking a local runtime busy flag. React can send a
separate stop request while it keeps reading the active stream:

```javascript
await fetch(`/conversations/${conversationId}/stop`, { method: 'POST' });
```

The stop handler authorizes the active execution and requests its shared signal:

```php
$stopSignal = new StopSignal($sharedStorage, $authorizedExecutionKey);
$stopSignal->request();
```

A fresh runtime in the stop request is not the executing runtime. In-memory
storage works only within one process; separate requests require shared storage
and an execution key that does not accidentally target a subsequent response.
The provider must use the same signal in its polling callback. Stream start clears
stale stop requests; hosts should request stop only for the intended execution. Local runtime
responseStopRequested()/responseWasStopped() track requests made through that
runtime; an external stop handler signals the provider directly. Neuron's final
message carries the native stopped-response metadata when available.

Disconnecting fetch alone is not a guarantee that generation stops. Response stop
does not cancel running tools or promise whole-turn/remote-provider cancellation.
See [response stop](response-stop.md) for native HTTP setup and limits.

## Commands and concurrent requests

Conversation dispatches Commands and executes their prompts in the current stream.
The host presents Notification, SelectionRequest, ExitRequest, SessionChanged and
AgentChanged alongside native events. It does not queue generated prompts or
create a preview of a generated UserMessage. A chosen option is a later CommandInput;
closing a picker without a choice submits nothing.

Availability belongs to the host, through Conversation's admitCommand closure.
The TUI keeps help and exit available while busy and refuses ordinary Commands,
including selection responses. Human inputs remain in its FIFO. State-changing
commands immediately bind the selected owned Session or Agent; already started
responses retain their captured Agent and Session. See [Commands](commands.md)
for request ordering, errors and portable HTTP selection.

The runtime provides no session execution lock. If multiple tabs or clients can
write the same Session, the web application chooses concurrency admission. It can
use Symfony Lock around loading the session and consuming its stream, without
a lock abstraction in chat-core. Neuron's workflow execution admission does
not by itself establish atomicity for every Session mutation or client operation.
Durable pending queues, workers, reconnect and replay are outside this delivery.

The evidence behind the client-queue decision is recorded in
[the web queue research](../.scratch/conversation-runtime/research-web-message-queue.md).

## Unified input and Command notifications

`submitInput(string|UserMessage|CommandInput)` is the public submission method.
Strings such as `/echo hello` dispatch the registered `/echo` Command with `hello`
as its argument. The argument remains opaque, including slash-prefixed values.
An explicit `UserMessage` always reaches the Agent, even when its text starts with
slash. Blank strings produce an empty stream; explicit empty messages retain
message preparation validation.

Construct a registry with `new Commands($first, $second)`. Identifiers consist of
`/` followed by letters, digits, underscores or hyphens; invalid and duplicate
identifiers throw at registration. The default registry is empty. `all()` and
`named()` provide consultation; the host submits input through Conversation.

Commands implement `run(CommandContext $context, string $value): void`. The
context supplies Agent, Session, their stores and a consultation list of Commands.
`notify($text, NotificationLevel::Info)` registers feedback; Warning and Error
use the same method. The host consumes `Notification` objects alongside native
Agent events and decides how to present their `text` and `level`.

Message preparation happens when submitted; Agent execution and Command dispatch
happen when the returned stream is consumed. Native event objects, keys and
`AgentState` are preserved. A Command without prompts returns null. Unknown
Commands emit an Error notification. Requests registered before a Command throws
are emitted before the original exception propagates; state changes remain.

An omitted ConfigurationStore uses isolated memory storage for each Conversation.
A supplied store is reused, allowing the host to persist its preferences.

Run `php examples/bin/00-input.php` for a minimal Command notification example
without provider credentials.
