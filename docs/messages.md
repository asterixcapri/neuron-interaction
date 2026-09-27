# User message processing

`NeuronInteraction\Message\UserMessageProcessorInterface` transforms complete
Neuron `UserMessage` objects, including text, attachments and metadata:

```php
public function forAgent(UserMessage $message): UserMessage;
public function forDisplay(UserMessage $message): UserMessage;
```

`forAgent()` prepares ordinary submitted input before it enters the Agent flow.
Commands and prompts already prepared by Commands bypass this step. Preparation
may throw to reject submission; the caller decides how to report the failure and
retain the draft.

`forDisplay()` projects the complete message before a frontend renders its
content blocks. It must not alter stored History, perform side effects or depend
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

$processing = new UserMessageProcessors([$first, $second]);
$prepared = $processing->forAgent($submitted);
$display = $processing->forDisplay($prepared);
```

Preparation applies `$first`, then `$second`. Display applies `$second`, then
`$first`. An empty pipeline returns an unchanged copy. Rendering, persistence,
command dispatch and error presentation belong to the caller.
