# User message processing

`NeuronInteraction\Message\UserMessageProcessorInterface` transforms complete
Neuron `UserMessage` objects, including text, attachments and metadata:

```php
public function forAgent(UserMessage $message): UserMessage;
public function forDisplay(UserMessage $message): UserMessage;
```

The Host Application supplies the processing module to `Conversation`
with `setUserMessageProcessors()`. `sendInput()` invokes `forAgent()` exactly
once synchronously for ordinary messages, before returning the lazy response stream. Frontend queue
entries are original input; they are prepared when the frontend submits them.
Command-generated prompts use the same processing pipeline once, when their
registered request is reached while consuming the stream. Processors must preserve content they recognize as already expanded.
Preparation exceptions propagate to the caller; prepared empty text without a
non-text attachment throws `InvalidArgumentException`. Rejection does not start
a Turn. The client reports the failure and retains its draft
and original input recall. Valid attachments and message metadata are preserved.

`forDisplay()` projects saved messages before a frontend renders their
content blocks. `Conversation::getMessages()` returns the saved Session history;
`getDisplayMessages()` applies `forDisplay()` to its UserMessages and leaves
other messages unchanged, without changing saved history. Live human input is shown immediately as submitted, without
applying either processor to its preview. Command-generated prompts have no automatic live human preview. Their saved
History is projected with `forDisplay()` when the host displays it. It must not alter stored History, perform side effects or depend
on how often the frontend renders. Repeated calls with the same original message
must produce the same representation. It need not reverse preparation or be
idempotent when applied to its own output.

`Session::getMessages()`, `Conversation::getMessages()` and
`Conversation::getDisplayMessages()` accept optional `limit` and `before`
parameters. A limit selects the most recent messages, returned in chronological
order. `before` is the ID of a message excluded from the page; an unknown ID
returns an empty page. With neither parameter, the complete history is returned.

```php
$messages = $conversation->getDisplayMessages(limit: 50);
if ($messages !== []) {
    $older = $conversation->getDisplayMessages(limit: 50, before: $messages[0]->getId());
}
```

Both methods return a new message and must not modify the received message or
its content blocks. Preserve unaffected attachments, block metadata and message
metadata. Processors may explicitly add, replace or remove content; the frontend
renders the returned blocks rather than substituting only the text. Unrecognized
content remains unchanged.

To expand references into model-readable tags, extend
`AbstractUserMessageTagProcessor`. Implement `tagName(): string` for the output
tag name and `contentFor(string $reference): ?string` for its contents. Returning
`file` from `tagName()` turns `@README.md` into
`<file name="README.md">...contents...</file>`. The default input prefix is `@`;
override `referencePrefix(): string` to use `/` for skill references.
Override `attributesFor(string $reference): array` to supply additional tag
attributes. The base class restores the original reference in `forDisplay()`.
Return `null` to leave a reference unchanged; throw to reject the input.
Already expanded text blocks are not expanded again. See example 07.

Compose processors with `UserMessageProcessors`:

```php
use NeuronInteraction\Message\UserMessageProcessors;

$processing = new UserMessageProcessors($first, $second);
$conversation = new Conversation($agent, $session);
$conversation->setUserMessageProcessors($processing);
$stream = $conversation->sendInput($submitted);
foreach ($stream as $chunk) {
    // Present the native Neuron output.
}
```

Preparation applies `$first`, then `$second`. Display applies `$second`, then
`$first`. An empty pipeline returns an unchanged copy. Rendering and error presentation belong to the client. The runtime owns
preparation and execution; pending client input is not yet prepared.

There is no public preparation step or alternate prompt submission method.
Clients show the original input, then call `sendInput()` and consume its
native stream. When reopening a conversation, use `getDisplayMessages()` to
project the saved Agent History. If preparation transforms content or attachments, the
reopened presentation can differ from the original live preview.

`addProcessor()` accepts one or more processors, mutates the collection and returns the same instance for chaining. Register
processors before running the host. `all()` returns them in registration order.
Pass processors to the constructor or register them later through `addProcessor()`.
