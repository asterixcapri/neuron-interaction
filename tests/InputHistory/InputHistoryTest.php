<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\InputHistory;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\Sessions;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InputHistoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/neuron-interaction-input-history-'
            . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    #[DataProvider('storageKinds')]
    public function testExistingAdaptersSeeEachOthersSubmissionsWithoutLosingEntries(bool $files): void
    {
        $storage = $files
            ? new FileStorage($this->directory)
            : new InMemoryStorage();
        $first = new InputHistory($storage);
        $second = new InputHistory($files
            ? new FileStorage($this->directory)
            : $storage);

        self::assertSame([], $first->entries());
        self::assertSame([], $second->entries());

        $first->record('  Message exactly as submitted  ');
        self::assertSame(['  Message exactly as submitted  '], $second->entries());

        $second->record('/resume session-key');
        $first->record("Another\nmessage");
        $expected = [
            '  Message exactly as submitted  ',
            '/resume session-key',
            "Another\nmessage",
        ];

        self::assertSame($expected, $first->entries());
        self::assertSame($expected, $second->entries());
        self::assertSame($expected, (new InputHistory($files
            ? new FileStorage($this->directory)
            : $storage))->entries());
    }

    #[DataProvider('storageKinds')]
    public function testBlankInputsAndOnlyConsecutiveExactDuplicatesAreIgnoredAcrossAdapters(bool $files): void
    {
        $storage = $files
            ? new FileStorage($this->directory)
            : new InMemoryStorage();
        $first = new InputHistory($storage);
        $second = new InputHistory($files
            ? new FileStorage($this->directory)
            : $storage);

        $first->record(" \n\t ");
        self::assertSame([], $second->entries());

        $first->record('same');
        $second->record('same');
        $first->record('');
        $second->record('different');
        $first->record('same');
        $second->record(' same ');

        self::assertSame(
            ['same', 'different', 'same', ' same '],
            $first->entries(),
        );
    }

    #[DataProvider('storageKinds')]
    public function testSessionsDoNotReplaceInputHistoryOrRecordGeneratedPrompts(bool $files): void
    {
        $storage = $files
            ? new FileStorage($this->directory)
            : new InMemoryStorage();
        $inputs = new InputHistory($storage);
        $sessions = new Sessions($storage);
        $history = $sessions->start();

        $inputs->record('/summarize');
        $history->addMessage(new UserMessage('A generated prompt for the Agent'));
        $key = $sessions->summaries()[0]->key;
        $sessions->start()->addMessage(new UserMessage('Another conversation'));
        $inputs->record('A submitted message');
        $sessions->resume($key);

        self::assertSame(
            ['/summarize', 'A submitted message'],
            (new InputHistory($storage))->entries(),
        );
        self::assertCount(2, $sessions->summaries());
    }

    /** @return array<string, array{bool}> */
    public static function storageKinds(): array
    {
        return ['memory' => [false], 'files' => [true]];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path)
                ? $this->removeDirectory($path)
                : unlink($path);
        }

        rmdir($directory);
    }
}
