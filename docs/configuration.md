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

`read($key, $fallback = null)` returns the fallback only for an absent key,
without changing storage. An explicitly stored `null` remains `null` even with
a non-null fallback. `entries()` returns an associative PHP array of keys and
values, or an empty array, with no ordering guarantee.

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
$adapter->configurationStore()->write('model', $arguments->text);
```

Model meanings and provider construction belong to the Host Application.
Neuron TUI's demo updates the current Agent's provider and preserves its History.
Opening or cancelling its model selector does not save a choice.

Storage handles namespaced JSON documents. SessionStore creates and finds
conversations; each Session is a self-persisting Neuron Chat History.
ConfigurationStore reads and writes individual preferences directly. These
modules may share Storage without sharing their public protocols.

## Compatibility

This replaces the public Configuration object and named-configuration protocol.
Direct preferences use a separate storage namespace. Old named configurations
are neither discovered nor imported, overwritten or deleted by this API. A
previously saved model in a named configuration therefore does not initialize
the new preference; the Host Application uses its fallback until a new choice
is written. Migration is a separate application decision.

Run `php examples/configuration.php` for a file-backed example.
