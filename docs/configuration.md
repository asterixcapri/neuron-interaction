# ConfigurationStore

ConfigurationStore provides direct access to one user's stored preferences.
The Host Application supplies Storage and an explicit user identity once.
Keys such as `model` identify individual preferences; they are literal strings,
not profile names, filesystem paths or nested-property expressions.

```php
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\FileStorage;

$store = new ConfigurationStore(new FileStorage($dataDirectory), $userId);
$model = $store->read('model', 'initial-model');
$store->write('model', 'another-model');
$store->write('temperature', 0.5);
$store->delete('obsoleteOption');
$preferences = $store->entries();
```

`read($key, $fallback = null)` uses the fallback to select the expected PHP type.
A missing or incompatible value returns the fallback, without changing storage
or converting the stored value. Strings must also be non-empty (`!== ''`).

```php
$model = $store->read('model', 'initial-model'); // non-empty-string
$retries = $store->read('retries', 3);           // int
$temperature = $store->read('temperature', 0.5); // float
$enabled = $store->read('enabled', false);      // bool
$tools = $store->read('tools', []);             // array
$optional = $store->read('model');              // non-empty-string|null
```

Without a fallback, or with an explicit `null`, a read accepts only a non-empty
string and returns `null` otherwise. A stored `null` therefore returns a non-null
fallback when one is supplied. Integer and float are distinct: a stored integer
is incompatible with a float fallback, and numeric strings are not converted.
`'0'`, `0`, `0.0`, `false` and `[]` remain valid for their respective types.
Whitespace-only strings are non-empty; model syntax remains the application's
responsibility.

Array fallbacks select only the PHP array type, not an element type, list shape
or required keys. PHPStan return types follow these runtime guarantees and do
not promise the fallback's literal value or array structure. An empty string
fallback or non-JSON-compatible fallback throws `InvalidArgumentException`, even
when a stored value exists. `null` is the supported no-fallback sentinel.

`entries()` returns the original associative PHP array of keys and values,
including nulls, empty strings and mixed value types. An empty Store returns an
empty array, with no ordering guarantee. Resolution on read never repairs or
rewrites stored data, and Storage failures propagate rather than using a fallback.

`write($key, $value)` creates or replaces one preference and preserves the
others. `delete($key)` removes one preference; deleting an absent key is a
no-op. Both return void and complete through the supplied Storage before
returning. There is no separate create, load or save operation. FileStorage
persists to files; InMemoryStorage is transient. This does not promise disk
synchronization, cross-process transactions or coordinated provider rollback.

Values may be JSON scalars, null, lists and nested PHP arrays. Objects,
resources, recursive arrays, non-finite numbers and invalid encodings are
rejected with `InvalidArgumentException` before changing saved values. Arrays
supplied to writes or returned by reads and entries are detached data: later
mutations require an explicit write to affect preferences. Storage failures
propagate to the caller. Users sharing Storage have independent preferences.

Commands obtain the Host Application's shared Store through
`$adapter->configurationStore()`. For example:

```php
$adapter->configurationStore()->write('model', $value);
```

Model meanings and provider construction belong to the Host Application.
Neuron TUI's demo updates the current Agent's provider and preserves its History.
Opening or cancelling its model selector does not save a choice.

Storage handles namespaced JSON documents. SessionStore creates and finds
conversations; each Session is a self-persisting Neuron Chat History.
ConfigurationStore reads and writes individual preferences directly. These
modules may share Storage without sharing their public protocols.

## Compatibility

Fallback-selected reads replace the earlier raw-value `read()` contract.
Callers reading numbers, booleans or arrays must now supply an appropriate
fallback. Callers needing the original values, including an explicit null or
empty string, can use `entries()`. Writes, deletion and the on-disk format are
unchanged by this read-contract revision.


This replaces the public Configuration object and named-configuration protocol.
Direct preferences use a separate storage namespace. Old named configurations
are neither discovered nor imported, overwritten or deleted by this API. A
previously saved model in a named configuration therefore does not initialize
the new preference; the Host Application uses its fallback until a new choice
is written. Migration is a separate application decision.

Run `php examples/configuration.php` for a file-backed example.
