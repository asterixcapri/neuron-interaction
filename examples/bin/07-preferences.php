<?php

declare(strict_types=1);

use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\FileStorage;

require_once __DIR__ . '/../vendor/autoload.php';

$directory = \dirname(__DIR__) . '/.storage/preferences';
$settings = new ConfigurationStore(new FileStorage($directory), 'demo-user');
echo 'Current language: ' . $settings->read('language', 'English') . \PHP_EOL;

// Pass a language to save it; run without arguments to read the remembered value.
$language = $argv[1] ?? null;
if ($language !== null) {
    if (!\in_array($language, ['English', 'Italian'], true)) {
        throw new InvalidArgumentException('Choose English or Italian.');
    }
    $settings->write('language', $language);
    echo 'Saved language: ' . $language . \PHP_EOL;
}

// A fresh Store restores this user's preference; another user has its own fallback.
$reopened = new ConfigurationStore(new FileStorage($directory), 'demo-user');
echo 'Language in a fresh Store: ' . $reopened->read('language', 'English') . \PHP_EOL;
$anotherUser = new ConfigurationStore(new FileStorage($directory), 'another-user');
echo "Another user's language: " . $anotherUser->read('language', 'English') . \PHP_EOL;
