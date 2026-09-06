<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\FileStorage;

// The host supplies the identity and decides which configuration is active.
$configurationStore = new ConfigurationStore(
    new FileStorage(sys_get_temp_dir() . '/neuron-interaction-example'),
    'local-demo',
);

$configuration = $configurationStore->read('default')
    ?? $configurationStore->create('default', [
        'model' => 'initial-model',
        'provider' => 'example-provider',
        'tools' => ['search'],
    ]);

$configuration->set('model', 'another-model');
// Related changes stay in memory until this explicit save. Provider and tools remain.
$configurationStore->save($configuration);

// The host may now use these values to construct its Agent.
echo json_encode($configuration->all(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
