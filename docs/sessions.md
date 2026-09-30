# Sessions and Storage

```php
use NeuronAI\Agent\Agent;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Session\SessionTitleGenerator;
use NeuronInteraction\Storage\FileStorage;

$storage = new FileStorage(__DIR__ . '/interaction-state');
$sessionStore = new SessionStore($storage, 'local-user');
$agent = new Agent();
$agent = $sessionStore->create()->bindTo($agent);

// After the Agent has exchanged messages, list recognizable Sessions.
foreach ($sessionStore->summaries() as $session) {
    // Render $session->title ?? 'New session'.
    // Resume a chosen Session by binding the Agent to its thread:
    // $conversation = $sessionStore->read($session->key);
    // if ($conversation !== null) { $agent = $conversation->bindTo($agent); }
}
```

Host Applications explicitly bind a Session from `create()` or `read($key)`
to the Agent when they want that conversation managed by this SessionStore.
SessionStore does not import arbitrary Agent conversations or automatically select the
latest conversation. Only Histories managed through this Store appear in
its listing, subject to the existing title rules.

Use `InMemoryStorage` for transient state, or implement `StorageInterface`
for application-specific persistence. Storage holds namespaced JSON documents
identified by logical keys. It preserves string metadata together with data;
`StoredDocument::size()` reports the JSON size of its data.

`SessionStore::create()` creates a distinct empty conversation. `SessionStore::summaries()`
returns Sessions with user-authored text or attachments, ordered by most recent
use and then key. Titles are independent metadata: `Session::getTitle()` returns
the saved title or null, and `Session::setTitle()` saves a non-blank title without
changing messages or the last-used time. Summaries expose that same nullable
title. The Store does not infer titles from messages or generate them.
Empty sessions remain excluded. `SessionStore::read($key)`
reopens its stored conversation or returns null for absent or other-user keys.
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

## Generating titles

`SessionTitleGenerator` generates a title from a Session using an injected `AIProviderInterface`.
It reads the history messages directly, ignores reasoning and tool activity, and calls `structured()` on a dedicated `SessionTitleAgent` that owns the title instructions. It returns null when no topic has emerged; provider errors
propagate to the host. It never modifies the supplied messages or Session.

```php
$generator = new SessionTitleGenerator($provider, $session);
$title = $generator->generate();
if ($title !== null && $session->getTitle() === null) {
    $session->setTitle($title);
}
```

The host decides when to run this operation and how to report errors.
Neuron TUI runs it automatically in the background after successful turns.

## Neuron AI 4 message storage

`Session` represents one saved conversation: its key, owner, title, metadata and
messages. It does not extend Neuron's `ChatHistory`. `SessionMessageStore`
implements `MessageStoreInterface` and handles persistence.

`$session->getMessages()` reads the complete conversation, including archived
messages, from storage on every call. Existing Session instances therefore see
messages saved by later turns. Listings include conversations whose user messages
have been archived.

`bindTo()` returns an Agent copy bound to the Session key and message store. Always
keep the returned Agent. The provider, tools and context window remain configured
on the Agent; the Session does not choose a token budget.

```php
$agent = $session->bindTo($agent);
$conversation = $session->getMessages(); // All saved messages, including archives.
$context = $agent->getChatHistory()->getMessages(); // Active model context.
```

Neuron creates its own `ChatHistory` on each `getChatHistory()` call. That history
validates message sequences and archives older messages when trimming the model
context. Obtain a fresh history after a turn; a previously loaded history retains
its active-message snapshot. Clearing it deletes active and archived messages.
Do not write through another history while an Agent turn is running.

Existing Neuron AI 3 Session documents remain readable through Neuron's
`MessageDeserializer`. New writes use Neuron AI 4 message serialization and
preserve message IDs, content blocks, metadata and structured tool outputs.
