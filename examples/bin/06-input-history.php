<?php

declare(strict_types=1);

use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Storage\FileStorage;

require_once __DIR__ . '/../vendor/autoload.php';

$directory = \dirname(__DIR__) . '/.storage/input-history';
$inputs = new InputHistory(new FileStorage($directory));

// Record original submissions, including Command syntax, independently of Sessions.
$inputs->record(new UserMessage('Explain PHP generators'));
$inputs->record(new UserMessage('/help'));

// Another client can read the same submissions without an Agent or a Session.
$inputs = new InputHistory(new FileStorage($directory));
echo '=== Recorded inputs ===' . \PHP_EOL;
foreach ($inputs->entries() as $input) {
    echo 'You: ' . $input->getContent() . \PHP_EOL;
}

$draft = new UserMessage('My unfinished question');
echo \PHP_EOL . 'Draft: ' . $draft->getContent() . \PHP_EOL;
echo 'Up: ' . $inputs->older($draft)?->getContent() . \PHP_EOL;
echo 'Up again: ' . $inputs->older()?->getContent() . \PHP_EOL;
echo 'Down: ' . $inputs->newer()?->getContent() . \PHP_EOL;
echo 'Down again, draft restored: ' . $inputs->newer()?->getContent() . \PHP_EOL;
