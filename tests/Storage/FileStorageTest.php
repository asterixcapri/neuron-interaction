<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Storage;

use Generator;
use InvalidArgumentException;
use NeuronInteraction\Storage\FileStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileStorageTest extends TestCase
{
    public function testSuppliedKeysCannotBeCreatedTwice(): void
    {
        $storage = new FileStorage($this->directory);
        $created = $storage->create('demo', ['value' => 'first'], key: 'chosen');
        self::assertSame('chosen', $created->key);
        try {
            $storage->create('demo', ['value' => 'second'], key: 'chosen');
            self::fail('An existing key must not be overwritten.');
        } catch (\RuntimeException) {
            self::assertSame(['value' => 'first'], $storage->read('demo', 'chosen')?->data);
        }
    }

    public function testEntriesFilterExactMetadataWithAnd(): void
    {
        $storage = new FileStorage($this->directory);
        $match = $storage->create('demo', [], ['userId' => 'alice', 'project' => 'one', 'extra' => 'yes']);
        $storage->create('demo', [], ['userId' => 'alice', 'project' => 'two']);
        $storage->create('demo', [], ['userId' => 'bob', 'project' => 'one']);
        $storage->create('demo', [], ['userId' => 'alice']);
        $storage->create('demo', [], ['userId' => 'Alice', 'project' => 'one']);

        $entries = iterator_to_array($storage->entries('demo', ['userId' => 'alice', 'project' => 'one']));
        self::assertSame([$match->key], array_column($entries, 'key'));
        self::assertCount(5, iterator_to_array($storage->entries('demo')));
        self::assertSame([], iterator_to_array($storage->entries('demo', ['missing' => ''])));
    }

    public function testCompetingCreatesPublishOnlyOneCompleteDocument(): void
    {
        $script = <<<'PHP'
require $argv[1];
$storage = new \NeuronInteraction\Storage\FileStorage($argv[2]);
fgets(STDIN);
try {
    $storage->create('race', ['winner' => $argv[3], 'payload' => str_repeat('x', 100000)], key: 'shared');
    exit(0);
} catch (\RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage());
    exit(2);
}
PHP;
        $workers = [];
        for ($index = 0; $index < 8; ++$index) {
            $process = proc_open(
                [PHP_BINARY, '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $this->directory, (string) $index],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $workers[] = [$process, $pipes];
        }
        foreach ($workers as [, $pipes]) {
            fwrite($pipes[0], "start\n");
            fclose($pipes[0]);
        }

        $winners = [];
        foreach ($workers as $index => [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            if ($status === 0) {
                $winners[] = (string) $index;
            } else {
                self::assertSame(2, $status, $error);
                self::assertSame('The storage key already exists.', $error);
            }
        }
        self::assertCount(1, $winners);
        $document = (new FileStorage($this->directory))->read('race', 'shared');
        self::assertSame(['winner' => $winners[0], 'payload' => str_repeat('x', 100000)], $document?->data);
    }

    public function testNumericStringKeysRemainStringsWhenListed(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->write('demo', '123', ['value' => 'numeric key']);

        $entries = iterator_to_array($storage->entries('demo'));

        self::assertCount(1, $entries);
        self::assertSame('123', $entries[0]->key);
        self::assertSame(['value' => 'numeric key'], $entries[0]->data);
        self::assertEquals($storage->read('demo', '123'), $entries[0]);
    }

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/neuron-interaction-storage-'
            . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testCreateReturnsANewKeyAndWritesItsJsonFile(): void
    {
        $storage = new FileStorage($this->directory);
        $document = $storage->create(
            'sessions',
            ['value' => 'conversation'],
        );

        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{32}$/D',
            $document->key,
        );
        self::assertSame(['value' => 'conversation'], $document->data);
        self::assertFileExists(
            $this->directory
                . '/sessions/'
                . $document->key
                . '.json',
        );
    }

    public function testReadingAMissingValueDoesNotCreateTheRoot(): void
    {
        $storage = new FileStorage($this->directory);

        self::assertNull($storage->read('sessions', 'missing'));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testListingAMissingNamespaceDoesNotCreateTheRoot(): void
    {
        $storage = new FileStorage($this->directory);

        self::assertSame([], iterator_to_array($storage->entries('sessions')));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testNamespacesAreSeparateDirectories(): void
    {
        $storage = new FileStorage($this->directory);

        $storage->write('sessions', 'known', ['value' => 'conversation']);
        $storage->write('input-history', 'known', ['value' => 'commands']);

        self::assertSame(
            ['value' => 'conversation'],
            $storage->read('sessions', 'known')?->data,
        );
        self::assertSame(
            ['value' => 'commands'],
            $storage->read('input-history', 'known')?->data,
        );
    }

    public function testWritingAnExistingValueAtomicallyReplacesItsFile(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->write('sessions', 'known', ['value' => 'before']);

        $written = $storage->write(
            'sessions',
            'known',
            ['value' => 'after'],
        );

        self::assertSame(
            ['value' => 'after'],
            $written->data,
        );
        self::assertSame(
            ['known.json'],
            array_values(array_diff(
                scandir($this->directory . '/sessions') ?: [],
                ['.', '..'],
            )),
        );
    }

    #[DataProvider('unsafeComponents')]
    public function testUnsafeNamespacesAreRejected(string $component): void
    {
        $storage = new FileStorage($this->directory);

        $this->expectException(InvalidArgumentException::class);

        $storage->write($component, 'key', ['value']);
    }

    #[DataProvider('unsafeComponents')]
    public function testUnsafeKeysAreRejected(string $component): void
    {
        $storage = new FileStorage($this->directory);

        $this->expectException(InvalidArgumentException::class);

        $storage->write('namespace', $component, ['value']);
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function unsafeComponents(): Generator
    {
        yield 'empty' => [''];
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['..'];
        yield 'traversal' => ['../outside'];
        yield 'embedded traversal' => ['safe/../../outside'];
        yield 'absolute path' => ['/outside'];
        yield 'backslash traversal' => ['..\\outside'];
        yield 'backslash separator' => ['safe\\outside'];
        yield 'null byte' => ["safe\0outside"];
    }

    public function testDotsThatDoNotNameADirectoryBoundaryRemainValid(): void
    {
        $storage = new FileStorage($this->directory);

        $storage->write('version..sessions', 'history..json', ['value']);

        self::assertSame(
            ['value'],
            $storage->read('version..sessions', 'history..json')?->data,
        );
    }

    public function testASymbolicLinkCannotRedirectANamespaceOutsideTheRoot(): void
    {
        $outside = $this->directory . '-outside';
        mkdir($this->directory);
        mkdir($outside);
        symlink($outside, $this->directory . '/sessions');

        try {
            $this->expectException(RuntimeException::class);
            (new FileStorage($this->directory))->write(
                'sessions',
                'known',
                ['value'],
            );
        } finally {
            unlink($this->directory . '/sessions');
            rmdir($outside);
        }
    }

    public function testASymbolicLinkCannotRedirectAKeyOutsideTheRoot(): void
    {
        $outside = $this->directory . '-outside';
        mkdir($this->directory . '/sessions', 0777, true);
        file_put_contents($outside, 'untouched');
        symlink($outside, $this->directory . '/sessions/known.json');

        try {
            $this->expectException(RuntimeException::class);
            (new FileStorage($this->directory))->write(
                'sessions',
                'known',
                ['replacement'],
            );
        } finally {
            unlink($this->directory . '/sessions/known.json');
            unlink($outside);
        }
    }

    public function testEntriesHideTheFileExtensionAndExposeJsonSize(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->write('sessions', 'known', ['value' => 'conversation']);

        $entries = iterator_to_array($storage->entries('sessions'));

        self::assertCount(1, $entries);
        self::assertSame('known', $entries[0]->key);
        self::assertSame(['value' => 'conversation'], $entries[0]->data);
        self::assertSame(
            strlen('{"value":"conversation"}'),
            $entries[0]->size(),
        );
    }

    public function testMetadataRoundTripsWithData(): void
    {
        $storage = new FileStorage($this->directory);
        $written = $storage->write(
            'sessions',
            'known',
            ['value' => 'conversation'],
            ['lastUsedAt' => '2026-09-03T12:00:00+00:00'],
        );

        self::assertSame(
            ['lastUsedAt' => '2026-09-03T12:00:00+00:00'],
            $written->metadata,
        );
        self::assertSame(
            $written->metadata,
            $storage->read('sessions', 'known')?->metadata,
        );
    }

    public function testDeleteIsIdempotent(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->write('sessions', 'known', ['value']);

        $storage->delete('sessions', 'known');
        $storage->delete('sessions', 'known');

        self::assertNull($storage->read('sessions', 'known'));
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
