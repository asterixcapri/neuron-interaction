<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Configuration;

use InvalidArgumentException;
use JsonSerializable;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\EntryStorageInterface;
use NeuronInteraction\Storage\FileEntryStorage;
use NeuronInteraction\Storage\InMemoryEntryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function bin2hex;
use function dirname;
use function fclose;
use function fgets;
use function fopen;
use function fwrite;
use function glob;
use function hash;
use function is_dir;
use function is_resource;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function unlink;

use const GLOB_BRACE;
use const INF;
use const NAN;
use const PHP_BINARY;

final class ConfigurationStoreTest extends TestCase
{
    public function testCompetingInitializationPreservesIndependentPreferences(): void
    {
        $script = <<<'PHP'
            use NeuronInteraction\Configuration\ConfigurationStore;
            use NeuronInteraction\Storage\FileEntryStorage;

            require $argv[1];
            $store = new ConfigurationStore(new FileEntryStorage($argv[2]), 'alice');
            echo "ready\n";
            fgets(STDIN);
            $store->set($argv[3], 'value:' . $argv[3]);
            PHP;
        $workers = [];
        try {
            for ($index = 0; $index < 8; ++$index) {
                $process = proc_open(
                    [PHP_BINARY, '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $this->directory, 'preference.' . $index],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
                self::assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "start\n");
                fclose($pipes[0]);
            }
            foreach ($workers as [$process, $pipes]) {
                stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error);
            }
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
        }

        $reopened = new ConfigurationStore(new FileEntryStorage($this->directory), 'alice');
        self::assertCount(8, $reopened->list());
        for ($index = 0; $index < 8; ++$index) {
            self::assertSame('value:preference.' . $index, $reopened->get('preference.' . $index));
        }
        $reopened->set('later', 'retained');
        self::assertCount(9, $reopened->list());
    }

    #[DataProvider('adapters')]
    public function testExactUserIdentitiesAndNullRemovalRemainDistinct(bool $file): void
    {
        $storage = $this->storage($file);
        $alice = new ConfigurationStore($storage, 'alice');
        $other = new ConfigurationStore($storage, 'Alice');
        $alice->set('a.b', null);
        $other->set('a.b', 'other');
        self::assertSame(['a.b' => null], $alice->list());
        $alice->delete('a.b');
        self::assertSame([], $alice->list());
        self::assertSame(['a.b' => 'other'], $other->list());
    }

    public function testInitializationFailuresAreNotTreatedAsDuplicateConflicts(): void
    {
        $storage = $this->createMock(EntryStorageInterface::class);
        $storage->expects(self::once())->method('create')->willThrowException(new RuntimeException('Initialization failed'));
        $storage->expects(self::never())->method('update');
        $store = new ConfigurationStore($storage, 'alice');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Initialization failed');
        $store->set('model', 'new');
    }

    #[DataProvider('adapters')]
    public function testNumericNamesRemainEditableWhenInsertedFirst(bool $file): void
    {
        $store = new ConfigurationStore($this->storage($file), 'alice');
        $store->set('0', 'zero');
        $store->set('1', 'one');
        $store->set('0', 'updated');
        self::assertSame([0 => 'updated', 1 => 'one'], $store->list());
        $store->delete('0');
        self::assertSame([1 => 'one'], $store->list());
        $store->set('preference-1', 'distinct');
        self::assertSame('one', $store->get('1'));
        self::assertSame('distinct', $store->get('preference-1'));
    }

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-configuration-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (glob($this->directory . '/*/{*,.*}', GLOB_BRACE) ?: [] as $path) {
            if (is_dir($path)) {
                continue;
            }
            unlink($path);
        }
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            rmdir($path);
        }
        rmdir($this->directory);
    }

    /** @return iterable<string, array{bool}> */
    public static function adapters(): iterable
    {
        yield 'memory' => [false];
        yield 'file' => [true];
    }

    private function storage(bool $file): EntryStorageInterface
    {
        return $file ? new FileEntryStorage($this->directory) : new InMemoryEntryStorage();
    }

    public function testInvalidFallbackIsRejectedEvenWhenTheStoredValueIsUsable(): void
    {
        $store = new ConfigurationStore(new InMemoryEntryStorage(), 'alice');
        $store->set('model', 'chosen-model');
        foreach (['', INF, NAN, "\xB1", new stdClass(), [new stdClass()]] as $fallback) {
            try {
                $store->get('model', $fallback);
                self::fail('Fallbacks must be usable JSON data.');
            } catch (InvalidArgumentException) {
                self::assertSame('chosen-model', $store->get('model'));
            }
        }
    }

    #[DataProvider('adapters')]
    public function testFallbackDeterminesTheExactTypeWithoutCoercion(bool $file): void
    {
        $store = new ConfigurationStore($this->storage($file), 'alice');
        foreach ([
            [3, 0, '3'],
            [0, 12, 0.0],
            [1.5, 0.0, 1],
            [0.0, 2.5, '2.5'],
            [true, false, 0],
            [false, true, 'false'],
            [['default'], [], 'tools'],
            [[], ['nested' => [true, null, 12]], false],
        ] as [$fallback, $valid, $invalid]) {
            self::assertSame($fallback, $store->get('missing', $fallback));
            $store->set('preference', $valid);
            self::assertSame($valid, $store->get('preference', $fallback));
            $store->set('preference', $invalid);
            self::assertSame($fallback, $store->get('preference', $fallback));
            $store->set('preference', null);
            self::assertSame($fallback, $store->get('preference', $fallback));
        }
    }

    #[DataProvider('adapters')]
    public function testStringFallbackResolvesUnusablePreferencesWithoutChangingThem(bool $file): void
    {
        $store = new ConfigurationStore($this->storage($file), 'alice');
        foreach ([null, '', 0, false, []] as $value) {
            $store->set('model', $value);
            self::assertSame('default-model', $store->get('model', 'default-model'));
            self::assertNull($store->get('model'));
            self::assertSame(['model' => $value], $store->list());
        }
        foreach (['chosen-model', '0', ' '] as $value) {
            $store->set('model', $value);
            self::assertSame($value, $store->get('model', 'default-model'));
            self::assertSame($value, $store->get('model'));
        }
    }

    #[DataProvider('adapters')]
    public function testPreferencesAreWrittenImmediatelyAndReopened(bool $file): void
    {
        $storage = $this->storage($file);
        $store = new ConfigurationStore($storage, 'alice');
        self::assertNull($store->get('missing'));
        self::assertSame('fallback', $store->get('model', 'fallback'));
        self::assertSame([], $store->list());
        $store->set('model', 'first');
        $store->set('temperature', 1.0);
        $store->set('model', 'second');
        $store->set('nothing', null);
        $reopened = new ConfigurationStore($file ? new FileEntryStorage($this->directory) : $storage, 'alice');
        self::assertSame('second', $reopened->get('model'));
        self::assertSame('fallback', $reopened->get('nothing', 'fallback'));
        self::assertSame(['model' => 'second', 'temperature' => 1.0, 'nothing' => null], $reopened->list());
    }

    #[DataProvider('adapters')]
    public function testDeletingOnePreferencePreservesOtherPreferencesAndUsers(bool $file): void
    {
        $storage = $this->storage($file);
        $alice = new ConfigurationStore($storage, 'alice');
        $bob = new ConfigurationStore($storage, 'bob');
        $bob->delete('model');
        self::assertSame([], $bob->list());
        $alice->set('model', 'alice');
        self::assertNull($bob->get('model'));
        $bob->set('model', 'bob');
        $alice->set('tools', ['search']);
        $alice->delete('missing');
        self::assertSame('alice', $alice->get('model'));
        $alice->delete('model');
        $alice->delete('model');
        self::assertSame('fallback', $alice->get('model', 'fallback'));
        self::assertSame(['tools' => ['search']], $alice->list());
        self::assertSame(['model' => 'bob'], $bob->list());
        $alice->delete('tools');
        self::assertSame([], $alice->list());
    }

    #[DataProvider('adapters')]
    public function testInvalidValuesAreRejectedWithoutChangingConfiguration(bool $file): void
    {
        $store = new ConfigurationStore($this->storage($file), 'alice');
        $store->set('valid', 'original');
        $recursive = [];
        $recursive['self'] = &$recursive;
        $resource = fopen('php://memory', 'r');
        $object = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('Arbitrary objects must never be serialized.');
            }
        };

        try {
            foreach ([new stdClass(), ['nested' => $object], INF, NAN, "\xB1", $resource, $recursive] as $value) {
                try {
                    $store->set('valid', $value);
                    self::fail('Invalid values must be rejected.');
                } catch (InvalidArgumentException) {
                    self::assertSame('original', $store->get('valid'));
                }
                try {
                    $store->set('invalid', $value);
                    self::fail('Invalid initial values must be rejected.');
                } catch (InvalidArgumentException) {
                    self::assertNull($store->get('invalid'));
                }
            }
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    #[DataProvider('adapters')]
    public function testValuesAndReturnedArraysAreDetached(bool $file): void
    {
        $store = new ConfigurationStore($this->storage($file), 'alice');
        $nested = ['enabled' => true, 'limit' => 12, 'list' => [null, 'original']];
        $store->set('options', ['nested' => &$nested]);
        $nested['list'][1] = 'external mutation';
        $read = $store->get('options', []);
        $read['nested'] = 'changed';
        $entries = $store->list();
        $entries['options'] = 'changed';
        self::assertSame([
            'nested' => ['enabled' => true, 'limit' => 12, 'list' => [null, 'original']],
        ], $store->get('options', []));
    }

    public function testIndependentMemoryAdaptersDoNotSharePreferences(): void
    {
        $first = new ConfigurationStore(new InMemoryEntryStorage(), 'alice');
        $second = new ConfigurationStore(new InMemoryEntryStorage(), 'alice');
        $first->set('model', 'first');
        self::assertSame([], $second->list());
    }

    #[DataProvider('adapters')]
    public function testPreferenceKeysAreLiteral(bool $file): void
    {
        $store = new ConfigurationStore($this->storage($file), 'owner/with spaces');
        foreach (['', 'a.b', '../model', '0'] as $key) {
            $store->set($key, 'value:' . $key);
            self::assertSame('value:' . $key, $store->get($key));
        }
        self::assertSame(['' => 'value:', 'a.b' => 'value:a.b', '../model' => 'value:../model', 0 => 'value:0'], $store->list());
    }

    public function testMissingReadsAndDeletesDoNotRequireWritableStorage(): void
    {
        $storage = $this->createStub(EntryStorageInterface::class);
        $storage->method('get')->willReturn(null);
        $storage->method('update')->willThrowException(new RuntimeException('Storage is read-only'));
        $storage->method('create')->willThrowException(new RuntimeException('Storage is read-only'));
        $storage->method('delete')->willThrowException(new RuntimeException('Storage is read-only'));
        $store = new ConfigurationStore($storage, 'alice');
        self::assertSame('fallback', $store->get('model', 'fallback'));
        $store->delete('model');
        self::assertSame([], $store->list());
    }

    public function testWriteFailuresRemainObservable(): void
    {
        $storage = $this->createStub(EntryStorageInterface::class);
        $storage->method('update')->willThrowException(new RuntimeException('Storage unavailable'));
        $store = new ConfigurationStore($storage, 'alice');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Storage unavailable');
        $store->set('model', 'new');
    }

    #[DataProvider('adapters')]
    public function testLegacyDocumentsAreNeitherImportedNorChanged(bool $file): void
    {
        $storage = $this->storage($file);
        // Fixture written by the previous named-Configuration protocol.
        $legacyKey = hash('sha256', '5:alicedemo');
        $legacy = $storage->create('configurations', ['model' => 'legacy'], $legacyKey);
        $store = new ConfigurationStore($storage, 'alice');
        self::assertSame([], $store->list());
        $store->set('model', 'new');
        $store->delete('demo');
        $store->delete('model');
        self::assertSame(['model' => 'legacy'], $storage->get('configurations', $legacy));
    }

    public function testDeletionFailureRemainsObservableAndPreservesTheValue(): void
    {
        $storage = $this->createStub(EntryStorageInterface::class);
        $storage->method('get')->willReturn(['preference-model' => 'previous']);
        $storage->method('update')->willThrowException(new RuntimeException('Storage unavailable'));
        $storage->method('delete')->willThrowException(new RuntimeException('Storage unavailable'));
        $store = new ConfigurationStore($storage, 'alice');
        try {
            $store->delete('model');
            self::fail('Failed persistence must remain observable.');
        } catch (RuntimeException $exception) {
            self::assertSame('Storage unavailable', $exception->getMessage());
            self::assertSame('previous', $store->get('model'));
        }
    }
}
