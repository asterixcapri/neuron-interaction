<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Configuration;

use InvalidArgumentException;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
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
    public function testCreateAndReopenJsonValues(bool $file): void
    {
        $storage = $this->storage($file);
        $store = new ConfigurationStore($storage, 'owner@example.com');
        $values = [
            'model' => 'first',
            'temperature' => 1.0,
            'enabled' => true,
            'limit' => 12,
            'nothing' => null,
            'nested' => ['tools' => ['search', null, ['enabled' => false]]],
        ];
        $configuration = $store->create('personal/default', $values);
        $reopened = (new ConfigurationStore(
            $file ? new FileStorage($this->directory) : $storage,
            'owner@example.com',
        ))->read('personal/default');

        self::assertNotNull($reopened);
        self::assertSame($values, $reopened->all());
        self::assertSame('personal/default', $configuration->getKey());
        self::assertSame('owner@example.com', $reopened->getUserId());
        self::assertTrue($reopened->has('nothing'));
        self::assertNull($reopened->get('nothing', 'fallback'));
        self::assertFalse($reopened->has('absent'));
        self::assertSame('fallback', $reopened->get('absent', 'fallback'));
        self::assertNull($reopened->get('absent'));
        self::assertSame([], $store->create('empty')->all());
    }

    #[DataProvider('adapters')]
    public function testChangesRemainInMemoryUntilSavedTogether(bool $file): void
    {
        $storage = $this->storage($file);
        $store = new ConfigurationStore($storage, 'alice');
        $original = ['model' => 'old', 'provider' => 'one', 'tools' => ['search'], 'obsolete' => true];
        $store->create('default', $original);
        $configuration = $store->read('default');
        self::assertNotNull($configuration);
        $configuration->set('model', 'new');
        $configuration->set('provider', 'two');
        $configuration->set('temperature', 0.5);
        $configuration->remove('obsolete');
        $configuration->remove('missing');

        self::assertSame($original, $store->read('default')?->all());
        $store->save($configuration);
        $reopened = new ConfigurationStore($file ? new FileStorage($this->directory) : $storage, 'alice');
        self::assertSame([
            'model' => 'new',
            'provider' => 'two',
            'tools' => ['search'],
            'temperature' => 0.5,
        ], $reopened->read('default')?->all());
    }

    #[DataProvider('adapters')]
    public function testUsersHaveIndependentKeysReadsSavesAndDeletes(bool $file): void
    {
        $storage = $this->storage($file);
        $alice = new ConfigurationStore($storage, 'alice');
        $bob = new ConfigurationStore($storage, 'bob');
        $configuration = $alice->create('default', ['model' => 'alice']);
        self::assertNull($bob->read('default'));
        $bob->delete('default');
        $bob->create('default', ['model' => 'bob']);
        $configuration->set('model', 'alice-updated');
        $alice->save($configuration);
        self::assertSame('bob', $bob->read('default')?->get('model'));
        $alice->delete('default');
        $alice->delete('default');
        self::assertNull($alice->read('default'));
        self::assertSame('bob', $bob->read('default')->get('model'));
    }

    #[DataProvider('adapters')]
    public function testDuplicateCreationFailsWithoutOverwriting(bool $file): void
    {
        $store = new ConfigurationStore($this->storage($file), 'alice');
        $store->create('default', ['model' => 'first']);
        try {
            $store->create('default', ['model' => 'replacement']);
            self::fail('Duplicate creation must fail.');
        } catch (RuntimeException) {
            self::assertSame('first', $store->read('default')?->get('model'));
        }
    }

    #[DataProvider('adapters')]
    public function testInvalidValuesAreRejectedWithoutChangingConfiguration(bool $file): void
    {
        $store = new ConfigurationStore($this->storage($file), 'alice');
        $configuration = $store->create('default', ['valid' => 'original']);
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
                    $configuration->set('valid', $value);
                    self::fail('Invalid values must be rejected.');
                } catch (InvalidArgumentException) {
                    self::assertSame('original', $configuration->get('valid'));
                }
                try {
                    $store->create('invalid', ['value' => $value]);
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
}
