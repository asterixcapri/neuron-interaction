<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Configuration;

use InvalidArgumentException;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Storage\StoredDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigurationStoreTest extends TestCase
{
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
        foreach (glob($this->directory . '/*/*') ?: [] as $path) {
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

    private function storage(bool $file): StorageInterface
    {
        return $file ? new FileStorage($this->directory) : new InMemoryStorage();
    }

    #[DataProvider('adapters')]
    public function testPreferencesAreWrittenImmediatelyAndReopened(bool $file): void
    {
        $storage = $this->storage($file);
        $store = new ConfigurationStore($storage, 'alice');
        self::assertNull($store->read('missing'));
        self::assertSame('fallback', $store->read('model', 'fallback'));
        self::assertSame([], $store->entries());
        $store->write('model', 'first');
        $store->write('temperature', 1.0);
        $store->write('model', 'second');
        $store->write('nothing', null);
        $reopened = new ConfigurationStore($file ? new FileStorage($this->directory) : $storage, 'alice');
        self::assertSame('second', $reopened->read('model'));
        self::assertNull($reopened->read('nothing', 'fallback'));
        self::assertSame(['model' => 'second', 'temperature' => 1.0, 'nothing' => null], $reopened->entries());
    }

    #[DataProvider('adapters')]
    public function testDeletingOnePreferencePreservesOtherPreferencesAndUsers(bool $file): void
    {
        $storage = $this->storage($file);
        $alice = new ConfigurationStore($storage, 'alice');
        $bob = new ConfigurationStore($storage, 'bob');
        $bob->delete('model');
        self::assertSame([], $bob->entries());
        $alice->write('model', 'alice');
        self::assertNull($bob->read('model'));
        $bob->write('model', 'bob');
        $alice->write('tools', ['search']);
        $alice->delete('missing');
        self::assertSame('alice', $alice->read('model'));
        $alice->delete('model');
        $alice->delete('model');
        self::assertSame('fallback', $alice->read('model', 'fallback'));
        self::assertSame(['tools' => ['search']], $alice->entries());
        self::assertSame(['model' => 'bob'], $bob->entries());
        $alice->delete('tools');
        self::assertSame([], $alice->entries());
    }

    #[DataProvider('adapters')]
    public function testInvalidValuesAreRejectedWithoutChangingConfiguration(bool $file): void
    {
        $store = new ConfigurationStore($this->storage($file), 'alice');
        $store->write('valid', 'original');
        $recursive = [];
        $recursive['self'] = &$recursive;
        $resource = fopen('php://memory', 'r');
        $object = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('Arbitrary objects must never be serialized.');
            }
        };

        try {
            foreach ([new \stdClass(), ['nested' => $object], INF, NAN, "\xB1", $resource, $recursive] as $value) {
                try {
                    $store->write('valid', $value);
                    self::fail('Invalid values must be rejected.');
                } catch (InvalidArgumentException) {
                    self::assertSame('original', $store->read('valid'));
                }
                try {
                    $store->write('invalid', $value);
                    self::fail('Invalid initial values must be rejected.');
                } catch (InvalidArgumentException) {
                    self::assertNull($store->read('invalid'));
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
        $store->write('options', ['nested' => &$nested]);
        $nested['list'][1] = 'external mutation';
        $read = $store->read('options');
        self::assertIsArray($read);
        $read['nested'] = 'changed';
        $entries = $store->entries();
        $entries['options'] = 'changed';
        self::assertSame([
            'nested' => ['enabled' => true, 'limit' => 12, 'list' => [null, 'original']],
        ], $store->read('options'));
    }

    public function testIndependentMemoryAdaptersDoNotSharePreferences(): void
    {
        $first = new ConfigurationStore(new InMemoryStorage(), 'alice');
        $second = new ConfigurationStore(new InMemoryStorage(), 'alice');
        $first->write('model', 'first');
        self::assertSame([], $second->entries());
    }

    #[DataProvider('adapters')]
    public function testPreferenceKeysAreLiteral(bool $file): void
    {
        $store = new ConfigurationStore($this->storage($file), 'owner/with spaces');
        foreach (['', 'a.b', '../model', '0'] as $key) {
            $store->write($key, $key);
            self::assertSame($key, $store->read($key));
        }
        self::assertSame(['' => '', 'a.b' => 'a.b', '../model' => '../model', 0 => '0'], $store->entries());
    }

    public function testMissingReadsAndDeletesDoNotRequireWritableStorage(): void
    {
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('read')->willReturn(null);
        $storage->method('write')->willThrowException(new RuntimeException('Storage is read-only'));
        $storage->method('create')->willThrowException(new RuntimeException('Storage is read-only'));
        $storage->method('delete')->willThrowException(new RuntimeException('Storage is read-only'));
        $store = new ConfigurationStore($storage, 'alice');
        self::assertSame('fallback', $store->read('model', 'fallback'));
        $store->delete('model');
        self::assertSame([], $store->entries());
    }

    public function testWriteFailuresRemainObservable(): void
    {
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('write')->willThrowException(new RuntimeException('Storage unavailable'));
        $store = new ConfigurationStore($storage, 'alice');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Storage unavailable');
        $store->write('model', 'new');
    }

    #[DataProvider('adapters')]
    public function testLegacyDocumentsAreNeitherImportedNorChanged(bool $file): void
    {
        $storage = $this->storage($file);
        // Fixture written by the previous named-Configuration protocol.
        $legacyKey = hash('sha256', '5:alicedemo');
        $legacy = $storage->create('configurations', ['model' => 'legacy'], ['userId' => 'alice'], $legacyKey);
        $store = new ConfigurationStore($storage, 'alice');
        self::assertSame([], $store->entries());
        $store->write('model', 'new');
        $store->delete('demo');
        $store->delete('model');
        self::assertEquals($legacy, $storage->read('configurations', $legacyKey));
    }

    public function testDeletionFailureRemainsObservableAndPreservesTheValue(): void
    {
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('read')->willReturn(new StoredDocument('stored', ['model' => 'previous'], ['userId' => 'alice']));
        $storage->method('write')->willThrowException(new RuntimeException('Storage unavailable'));
        $storage->method('delete')->willThrowException(new RuntimeException('Storage unavailable'));
        $store = new ConfigurationStore($storage, 'alice');
        try {
            $store->delete('model');
            self::fail('Failed persistence must remain observable.');
        } catch (RuntimeException $exception) {
            self::assertSame('Storage unavailable', $exception->getMessage());
            self::assertSame('previous', $store->read('model'));
        }
    }
}
