# ConversationRuntime and the frontend/backend boundary

ConversationRuntime executes one message in a Session and returns Neuron's
response stream. React and Neuron TUI are frontends: they own input, pending
messages, presentation and the policy for starting the next turn.

History presentation follows the same boundary. Core stores and returns native
Neuron messages; each frontend chooses visible content and correlates tools for
its display. Neuron TUI projects directly into terminal entries using native
ToolCall, without core MessageEntry, ToolEntry or ToolData wrappers.

## Ordinary streaming

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Conversation\ConversationRuntime;

// $agent is configured by the host; $sessionStore belongs to the current user.
$runtime = new ConversationRuntime($agent, $sessionStore, session: $session);
$stream = $runtime->submitMessage(new UserMessage('Analyse this file'));
foreach ($stream as $chunk) {
    if ($chunk instanceof TextChunk) {
        echo $chunk->content;
    }
}
$state = $stream->getReturn(); // Neuron AgentState, including approval interruption.
```

There is no subscribe/executeNext pair. Preparation and rejection happen in
submitMessage(), before it returns. Iteration starts the Agent; each object is
delivered as it arrives. Text, tools, reasoning and other native output retain
identity and ordering. If the host configures a Neuron stream adapter, its output
also passes through without a second conversion.

An unstarted stream does not execute the Agent. ConversationRuntime exposes no
isBusy() and does not enforce execution admission. TUI scheduling coordinates its
own turns; web applications choose their own session concurrency policy. Neuron
may impose its own execution constraints. Errors propagate unchanged, without retry.

A started generator remains suspended when iteration pauses. Breaking foreach is
not a stop request. Release an abandoned stream (`unset($stream)`) and its other
references so Neuron can release its native execution resources. An unstarted
stream can be discarded without Agent execution.

submitMessage() is the only submission API, including for Command-generated
prompts. All submissions go through the configured processors once; processors
preserve content they recognize as already expanded. There is no public
preparation step. The frontend immediately shows original human input; when
reopening a conversation it projects saved native messages with forDisplay().
The live preview and the saved Agent History are separate representations.

## TUI script

```php
use NeuronInteraction\Conversation\ConversationRuntime;
use NeuronTui\Tui;

Tui::make(new ConversationRuntime($agent, $sessionStore))->run();
```

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
use NeuronInteraction\Conversation\ConversationRuntime;

$runtime = new ConversationRuntime(
    $agent,
    $sessionStore,
    session: $session,
    stopSignal: $stopSignal,
);
$stream = $runtime->submitMessage(new UserMessage($text));
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

UI commands (picker, notices, exit) operate on the frontend Adapter. Conversation
commands delegate Session and Agent operations to the core. Command prompts enter
the client's pending queue and use submitMessage() when their turn starts.
Collections remain explicitly mounted and invoke Commands::run() with a client
Adapter. Availability belongs to the frontend: TUI suggestions hide ordinary
Commands while busy and its Adapter checks current state before dispatch, including
later Selection choices. TUI counts its locally reserved turn as busy before
consumption. ConversationRuntime exposes no Command dispatch or availability
methods or busy flag; it validates Session ownership directly.
An active stream retains its captured Agent/Session even if a supported operation
selects a replacement for later execution.

The runtime provides no session execution lock. If multiple tabs or clients can
write the same Session, the web application chooses concurrency admission. It can
use Symfony Lock around loading the session and consuming its stream, without
a lock abstraction in chat-core. Neuron's workflow execution admission does
not by itself establish atomicity for every Session mutation or client operation.
Durable pending queues, workers, reconnect and replay are outside this delivery.

The evidence behind the client-queue decision is recorded in
[the web queue research](../.scratch/conversation-runtime/research-web-message-queue.md).
