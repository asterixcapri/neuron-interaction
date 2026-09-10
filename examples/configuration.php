<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\FileStorage;

// The host supplies the identity once; keys identify individual preferences.
$configurationStore = new ConfigurationStore(
    new FileStorage(sys_get_temp_dir() . '/neuron-interaction-example'),
    'local-demo',
);

// The fallback selects non-empty strings and does not create a saved preference.
$model = $configurationStore->read('model', 'initial-model');
echo 'Current model: ' . json_encode($model, JSON_THROW_ON_ERROR) . PHP_EOL;

$configurationStore->write('model', 'another-model');
$configurationStore->write('tools', ['search']);
$configurationStore->delete('obsoleteOption');

// Each write has already completed through Storage. No save step is needed.
echo json_encode($configurationStore->entries(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
