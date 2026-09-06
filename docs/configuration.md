# Configuration

A ConfigurationStore binds storage to the user supplied by the host application.
Each user can create a configuration with the same logical key, such as
`default`. Keys and user identities need no filesystem encoding from callers.

```php
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\FileStorage;

$store = new ConfigurationStore(new FileStorage($dataDirectory), $userId);
$configuration = $store->read('default')
    ?? $store->create('default', [
        'model' => 'initial-model',
        'provider' => 'example-provider',
        'tools' => ['search'],
    ]);

$configuration->set('model', 'another-model');
$configuration->set('temperature', 0.5);
$configuration->remove('obsoleteOption');
$store->save($configuration);
```

Creation immediately persists the initial values. Creating an existing key for
the same user throws `RuntimeException` and preserves the existing document,
including when file-backed creates compete.

A missing read returns `null`. `delete($key)` removes that user's configuration
if present. `getKey()` and `getUserId()` expose its key and owner.

`set()` and `remove()` affect memory only. A new read sees the previous values
until `save($configuration)` persists all changes together. Updating one option
preserves unrelated options. The supported flow is to create or read through a
Store and save through that same Store.

`has($name)` distinguishes a present null value from an absent value.
`get($name, $default)` returns the default only when the value is absent.
`all()` returns the complete values map.

Values may be JSON scalars, null, lists and nested PHP arrays. Objects,
resources, recursive arrays, non-finite numbers and invalid JSON strings are
rejected with `InvalidArgumentException`. Model, provider and other option
meanings belong to the host application. It also selects the active
configuration and constructs or replaces its Agent.

Run `php examples/configuration.php` for a file-backed example.
