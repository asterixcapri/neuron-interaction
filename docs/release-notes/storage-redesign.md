# Pending release notes: ordered Storage and isolated interaction state

These notes describe the Storage redesign for the next release in the current
release branch's series. No release or tag is created by this document.

## Breaking Storage API and data format

StorageInterface now provides ordered namespaced array entries through create,
get, set, update, delete, list and clear. FileStorage and InMemoryStorage implement
the same contract. StoredDocument, document metadata filtering, AbstractStorage,
and Storage's read, write and entries methods are removed.

Before:

```php
$document = $storage->create('application', ['title' => 'Draft'], ['project' => 'alpha']);
$key = $document->key;
$title = $storage->read('application', $key)?->data['title'];
$storage->write('application', $key, ['title' => 'Ready'], ['project' => 'alpha']);
```

After:

```php
$key = $storage->create('application', ['title' => 'Draft', 'metadata' => ['project' => 'alpha']]);
$title = $storage->get('application', $key)['title'] ?? null;
$storage->update('application', $key, [['path' => ['title'], 'value' => 'Ready']]);
$entries = $storage->list('application', 20);
```

Create returns a string key; supplied-key conflicts throw DuplicateKeyException
without replacing data. Get returns an array or null. Set replaces a complete
value; update assigns or removes literal nested fields atomically and throws
MissingEntryException for an absent target. Null assignment differs from removal.
Invalid updates leave the entry intact. List returns key/value pairs in first
insertion order; a positive limit selects the latest entries, still oldest first.
Zero returns none, negative limits fail. Replacement/update preserve position;
delete followed by recreate moves an entry to the end. Delete and namespace clear
are idempotent and do not affect other namespaces.

FileStorage stores one complete JSON file per entry, including each message and
submitted input. The previous file format is incompatible. There is no migration,
automatic legacy backfill or compatibility shim. Start with a fresh storage root
for new state. Retaining previous data requires an application-owned migration
before adopting this version. Listing scans the namespace even with bounded results.

## One Storage for user-scoped modules

SessionStore and ConfigurationStore continue to receive Storage and a user ID.
InputHistory now also requires that ID:

```php
$storage = new FileStorage(__DIR__ . '/new-interaction-state');
$userId = 'authenticated-user';
$sessions = new SessionStore($storage, $userId);
$preferences = new ConfigurationStore($storage, $userId);
$inputs = new InputHistory($storage, $userId);
```

The default SessionMessageStore persists Neuron messages through that same
Storage; a separate MessageStoreInterface is optional. Custom message stores
receive threads scoped by owning user and Session, and their lifecycle is wrapped
so direct Agent writes and clear update the Session record. Override duplicate
checks may scan saved messages; this does not affect the default Storage adapter. Session creation persists
its record immediately. Lookup and deletion establish user ownership; listing
excludes empty Sessions, filters application metadata and orders by latest use
with a key tie-breaker, without reading message histories. SessionSummary::getSize()
and the ResumeCommand byte-size label are removed. Title and metadata changes
preserve messages and last use through field updates.
ResumeCommand labels saved Sessions without a title as “Untitled session”.
The selection example displays last use and the Session key alongside the label.

Message IDs, structured payloads and archived messages survive reopening. Replayed
IDs do not replace saved messages, reset archive state or advance Session use.
New messages register use even through a directly bound Agent. Clear removes
active and archived messages and hides the empty Session from listings; Neuron
clear/reappend summarization remains supported. The command that starts a new
Session still retains the previous conversation. Session deletion removes its
record and messages.

ConfigurationStore exposes get, set, delete and list for direct user preferences;
replace former read, write and entries calls with get, set and list. Typed
fallback behavior remains: integers are not accepted as float preferences.
Independent preference changes preserve each other, including concurrent record
creation. Preference keys are literal names, including dots and numeric names.

InputHistory captures each original submission before processing or command
execution, across that user's Sessions and isolated from other users. Generated
command prompts are excluded; attachments, metadata and authored command syntax
are retained. Blank and reasoning-only inputs are skipped. Immediate repeated
inputs are compared without generated message IDs; separated repeats are retained.
List accepts an optional latest-entry limit. History has no retention limit.
StopSignal continues to support file-backed polling across instances.

Storage operations are individually atomic, with complete-value publication and
independent nested updates. This does not make message/Session registration,
batch archiving or Session deletion transactional. Applications serialize
concurrent workflows for the same Session. Simultaneous identical input-history
submissions may both be recorded because comparison and insertion are separate.
