# User message processing

`NeuronInteraction\Message\UserMessageProcessorInterface` transforms complete
Neuron `UserMessage` objects, including text, attachments and metadata:

```php
public function forAgent(UserMessage $message): UserMessage;
public function forDisplay(UserMessage $message): UserMessage;
```

The Host Application supplies the processing module to `Conversation`
with `userMessageProcessors:`. `submitMessage()` invokes `forAgent()` exactly
once synchronously, before returning the lazy response stream. Frontend queue
entries are original input; they are prepared when the frontend submits them.
Command-generated prompts use the same `submitMessage()` method and processing
pipeline. Processors must preserve content they recognize as already expanded.
Preparation exceptions propagate to the caller; prepared empty text without a
non-text attachment throws `InvalidArgumentException`. Rejection does not start
a Turn. The client reports the failure and retains its draft
and original input recall. Valid attachments and message metadata are preserved.

`forDisplay()` projects saved messages before a frontend renders their
content blocks. Live human input is shown immediately as submitted, without
applying either processor to its preview. Command-generated prompts may use
`forDisplay()` to hide expanded instructions in their preview. It must not alter stored History, perform side effects or depend
on how often the frontend renders. Repeated calls with the same original message
must produce the same representation. It need not reverse preparation or be
idempotent when applied to its own output.

Both methods return a new message and must not modify the received message or
its content blocks. Preserve unaffected attachments, block metadata and message
metadata. Processors may explicitly add, replace or remove content; the frontend
renders the returned blocks rather than substituting only the text. Unrecognized
content remains unchanged.

Compose processors with `UserMessageProcessors`:

```php
use NeuronInteraction\Message\UserMessageProcessors;

$processing = (new UserMessageProcessors())
    ->addProcessor($first)
    ->addProcessor([$second]);
$conversation = new Conversation($agent, $sessionStore, userMessageProcessors: $processing);
$stream = $conversation->submitMessage($submitted);
foreach ($stream as $chunk) {
    // Present the native Neuron output.
}
```

Preparation applies `$first`, then `$second`. Display applies `$second`, then
`$first`. An empty pipeline returns an unchanged copy. Rendering and error presentation belong to the client. The runtime owns
preparation and execution; pending client input is not yet prepared.

There is no public preparation step or alternate prompt submission method.
Clients show the original input, then call `submitMessage()` and consume its
native stream. When reopening a conversation, project the saved Agent History
with `forDisplay()`. If preparation transforms content or attachments, the
reopened presentation can differ from the original live preview.

Like `Commands::addCommand()`, `addProcessor()` accepts a single processor or an
array, mutates the collection and returns the same instance for chaining. Register
processors before running the host. `all()` returns them in registration order.
Create an empty collection with `new UserMessageProcessors()` and register all
processors through `addProcessor()`.
