<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Storage;

use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\StorageInterface;
use RuntimeException;
use UnexpectedValueException;

use function array_column;
use function array_diff;
use function array_keys;
use function bin2hex;
use function chmod;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_repeat;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

final class FileStorageTest extends StorageContract
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-entries-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    protected function storage(): StorageInterface
    {
        return new FileStorage($this->directory);
    }

    public function testDepthBoundarySurvivesReopeningAfterCreateSetAndUpdate(): void
    {
        $storage = new FileStorage($this->directory);
        $deep = self::nestedValue(511);
        $storage->create('depth', $deep, 'created');
        $storage->set('depth', 'replaced', []);
        $storage->set('depth', 'replaced', $deep);
        $storage->set('depth', 'updated', []);
        $updated = ['field' => self::nestedValue(510)];
        $storage->update('depth', 'updated', [['path' => ['field'], 'value' => self::nestedValue(510)]]);
        $reopened = new FileStorage($this->directory);
        self::assertSame($deep, $reopened->get('depth', 'created'));
        self::assertSame($deep, $reopened->get('depth', 'replaced'));
        self::assertSame($updated, $reopened->get('depth', 'updated'));
        self::assertSame($storage->list('depth'), $reopened->list('depth'));
    }

    public function testMissingReadsInAPrecreatedReadonlyNamespaceLeaveFilesystemUnchanged(): void
    {
        $namespace = $this->directory . '/empty';
        mkdir($namespace, recursive: true);
        $beforeRoot = scandir($this->directory);
        $beforeNamespace = scandir($namespace);
        chmod($namespace, 0o555);
        try {
            $storage = new FileStorage($this->directory);
            self::assertNull($storage->get('empty', 'absent'));
            self::assertSame([], $storage->list('empty'));
            self::assertSame($beforeRoot, scandir($this->directory));
            self::assertSame($beforeNamespace, scandir($namespace));
        } finally {
            chmod($namespace, 0o755);
        }
    }

    public function testReadDuringInitializationSeesMissingOrCommittedEntry(): void
    {
        mkdir($this->directory . '/items', recursive: true);
        $reader = new StorageWorker($this->directory);
        $writer = new StorageWorker($this->directory);
        try {
            $reader->send(['operation' => 'get', 'key' => 'new']);
            $writer->send(['operation' => 'create', 'key' => 'new', 'value' => ['committed' => true]]);
            $observed = $reader->receive()['result'];
            self::assertTrue($observed === null || $observed === ['committed' => true]);
            self::assertSame(['result' => 'new'], $writer->receive());
            self::assertSame(['committed' => true], (new FileStorage($this->directory))->get('items', 'new'));
        } finally {
            $reader->close();
            $writer->close();
        }
    }

    public function testNonemptyNamespaceWithoutCoordinationFailsClearly(): void
    {
        mkdir($this->directory . '/items', recursive: true);
        file_put_contents($this->directory . '/items/unexpected', 'uncoordinated state');
        $this->expectException(UnexpectedValueException::class);
        (new FileStorage($this->directory))->list('items');
    }

    public function testReopeningPreservesInsertionOrderAfterMutationsAndClear(): void
    {
        $storage = $this->storage();
        foreach (['z', 'b', 'a'] as $key) {
            $storage->create('items', ['key' => $key], $key);
        }
        $storage->set('items', 'z', ['replacement' => true]);
        $storage->update('items', 'b', [['path' => ['changed'], 'value' => true]]);
        $storage->delete('items', 'a');
        $storage->create('items', [], 'a');
        $reopened = $this->storage();
        self::assertSame(['z', 'b', 'a'], array_column($reopened->list('items'), 'key'));
        self::assertSame(['replacement' => true], $reopened->get('items', 'z'));
        $reopened->clear('items');
        $reopened->create('items', [], 'later');
        $this->storage()->create('items', [], 'earlier-name');
        self::assertSame(['later', 'earlier-name'], array_column($this->storage()->list('items'), 'key'));
    }

    public function testMissingReadsAndDeletesDoNotCreateDirectories(): void
    {
        $storage = $this->storage();
        self::assertNull($storage->get('items', 'absent'));
        self::assertSame([], $storage->list('items'));
        $storage->delete('items', 'absent');
        $storage->clear('items');
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testUpdatingAnEntryPreservesOtherEntriesAndNamespaces(): void
    {
        $storage = $this->storage();
        $storage->set('items', 'one', ['before' => true]);
        $storage->set('items', 'two', ['untouched' => true]);
        $storage->set('other', 'one', ['isolated' => true]);
        $storage->update('items', 'one', [['path' => ['after'], 'value' => true]], [['before']]);
        self::assertSame(['after' => true], $this->storage()->get('items', 'one'));
        self::assertSame(['untouched' => true], $this->storage()->get('items', 'two'));
        self::assertSame(['isolated' => true], $this->storage()->get('other', 'one'));
    }

    public function testCorruptEntryIsAnExplicitReadAndListFailure(): void
    {
        $storage = $this->storage();
        $storage->set('items', 'one', []);
        file_put_contents($this->directory . '/items/one.json', '{broken');
        try {
            $storage->get('items', 'one');
            self::fail('A corrupt entry must fail.');
        } catch (UnexpectedValueException) {
            $this->expectException(UnexpectedValueException::class);
            $storage->list('items');
        }
    }

    public function testNamespaceSymlinksCannotReadOrModifyOutsideTheRoot(): void
    {
        mkdir($this->directory);
        $outside = $this->directory . '/outside';
        mkdir($outside);
        symlink($outside, $this->directory . '/items');
        foreach ([fn() => $this->storage()->get('items', 'key'), fn() => $this->storage()->create('items', [])] as $operation) {
            try {
                $operation();
                self::fail('A symbolic namespace must fail.');
            } catch (RuntimeException) {
                self::assertSame([], array_diff(scandir($outside) ?: [], ['.', '..']));
            }
        }
    }

    public function testEntrySymlinksCannotReadOrModifyTheirTarget(): void
    {
        $storage = $this->storage();
        $storage->set('items', 'valid', []);
        $target = $this->directory . '/target';
        file_put_contents($target, 'untouched');
        symlink($target, $this->directory . '/items/linked.json');
        foreach ([
            fn() => $storage->get('items', 'linked'),
            fn() => $storage->set('items', 'linked', []),
            fn() => $storage->create('items', [], 'linked'),
            fn() => $storage->update('items', 'linked'),
            fn() => $storage->delete('items', 'linked'),
            fn() => $storage->list('items'),
            fn() => $storage->clear('items'),
        ] as $operation) {
            try {
                $operation();
                self::fail('An entry symlink must fail.');
            } catch (RuntimeException) {
                self::assertSame('untouched', file_get_contents($target));
            }
        }
    }

    public function testCompetingSuppliedKeyCreatesHaveExactlyOneWinner(): void
    {
        $workers = $this->workers(6);
        $value = ['payload' => str_repeat('complete', 2000)];
        try {
            foreach ($workers as $worker) {
                $worker->send(['operation' => 'create', 'key' => 'same', 'value' => $value]);
            }
            $successes = 0;
            $conflicts = 0;
            foreach ($workers as $worker) {
                $response = $worker->receive();
                if (isset($response['conflict'])) {
                    ++$conflicts;
                } else {
                    self::assertSame(['result' => 'same'], $response);
                    ++$successes;
                }
            }
            self::assertSame(1, $successes);
            self::assertSame(5, $conflicts);
            self::assertSame($value, $this->storage()->get('items', 'same'));
            self::assertCount(1, $this->storage()->list('items'));
        } finally {
            foreach ($workers as $worker) {
                $worker->close();
            }
        }
    }

    public function testConcurrentIndependentTopLevelAndNestedUpdatesSurvive(): void
    {
        $this->storage()->set('items', 'record', ['kept' => true, 'metadata' => ['kept' => true]]);
        $workers = $this->workers(6);
        try {
            foreach ($workers as $index => $worker) {
                $worker->send(['operation' => 'update', 'key' => 'record', 'assignments' => [
                    ['path' => ['field-' . $index], 'value' => $index],
                    ['path' => ['metadata', 'field-' . $index], 'value' => $index],
                ]]);
            }
            foreach ($workers as $worker) {
                self::assertSame(['result' => null], $worker->receive());
            }
            $value = $this->storage()->get('items', 'record');
            self::assertNotNull($value);
            self::assertTrue($value['kept']);
            self::assertIsArray($value['metadata']);
            self::assertTrue($value['metadata']['kept']);
            foreach (array_keys($workers) as $index) {
                self::assertSame($index, $value['field-' . $index]);
                self::assertSame($index, $value['metadata']['field-' . $index]);
            }
        } finally {
            foreach ($workers as $worker) {
                $worker->close();
            }
        }
    }

    public function testConcurrentReplacementReadersObserveCompleteValues(): void
    {
        $versions = [['version' => 'a', 'payload' => str_repeat('a', 24000)], ['version' => 'b', 'payload' => str_repeat('b', 24000)]];
        $this->storage()->set('items', 'record', $versions[0]);
        [$writer, $reader] = $this->workers(2);
        try {
            for ($round = 0; $round < 40; ++$round) {
                $writer->send(['operation' => 'set', 'key' => 'record', 'value' => $versions[$round % 2]]);
                $reader->send(['operation' => 'get', 'key' => 'record']);
                self::assertSame(['result' => null], $writer->receive());
                $value = $reader->receive()['result'];
                self::assertContains($value, $versions);
            }
        } finally {
            $writer->close();
            $reader->close();
        }
    }

    public function testConcurrentReadersAndDeletesObserveNullOrCompleteValues(): void
    {
        [$deleter, $reader] = $this->workers(2);
        $value = ['payload' => str_repeat('complete', 1000)];
        try {
            for ($round = 0; $round < 20; ++$round) {
                $this->storage()->set('items', 'record', $value);
                $deleter->send(['operation' => 'delete', 'key' => 'record']);
                $reader->send(['operation' => 'get', 'key' => 'record']);
                self::assertSame(['result' => null], $deleter->receive());
                self::assertContains($reader->receive()['result'], [null, $value]);
                self::assertNull($this->storage()->get('items', 'record'));
            }
        } finally {
            $deleter->close();
            $reader->close();
        }
    }

    public function testClearAndCreateProduceASerializableNamespaceSnapshot(): void
    {
        [$clearer, $creator, $reader] = $this->workers(3);
        try {
            for ($round = 0; $round < 15; ++$round) {
                $storage = $this->storage();
                $storage->clear('items');
                $before = [];
                for ($index = 0; $index < 8; ++$index) {
                    $key = 'old-' . $index;
                    $storage->create('items', ['old' => true], $key);
                    $before[] = ['key' => $key, 'value' => ['old' => true]];
                }
                $new = ['key' => 'new', 'value' => ['new' => true]];
                $clearer->send(['operation' => 'clear']);
                $creator->send(['operation' => 'create', 'key' => 'new', 'value' => $new['value']]);
                $reader->send(['operation' => 'list']);
                self::assertSame(['result' => null], $clearer->receive());
                self::assertSame(['result' => 'new'], $creator->receive());
                self::assertContains($reader->receive()['result'], [[], [$new], $before, [...$before, $new]]);
                self::assertContains($storage->list('items'), [[], [$new]]);
            }
        } finally {
            $clearer->close();
            $creator->close();
            $reader->close();
        }
    }

    /** @return list<StorageWorker> */
    private function workers(int $count): array
    {
        $workers = [];
        for ($index = 0; $index < $count; ++$index) {
            $workers[] = new StorageWorker($this->directory);
        }
        return $workers;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
