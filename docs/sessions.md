# Sessions and Storage

```php
use NeuronAI\Agent\Agent;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;

$storage = new FileStorage(__DIR__ . '/interaction-state');
$sessionStore = new SessionStore($storage, 'local-user');
$agent = new Agent();
$agent->setChatHistory($sessionStore->create());

// After the Agent has exchanged messages, list recognizable Sessions.
foreach ($sessionStore->summaries() as $session) {
    // Render $session->title according to your Adapter's rules.
    // Resume a chosen Session by installing its History on the Agent:
    // $history = $sessionStore->read($session->key);
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
returns Sessions with user-authored text or attachments, ordered by most recent
use and then key. Titles preserve the first non-blank user-authored textual
content without escaping or truncation. If no such text exists, an attachment
supplies a filename or a placeholder such as `[Image]`, `[File]`, `[Audio]` or
`[Video]`. Empty sessions remain excluded. `SessionStore::read($key)`
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
