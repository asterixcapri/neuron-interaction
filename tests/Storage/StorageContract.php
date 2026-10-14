<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Storage;

use InvalidArgumentException;
use NeuronInteraction\Exception\DuplicateKeyException;
use NeuronInteraction\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_column;

use const INF;

/** Shared public outcomes: each adapter supplies only its factory. */
abstract class StorageContract extends TestCase
{
    abstract protected function storage(): StorageInterface;

    public function testCreateAndMissingReads(): void
    {
        $storage = $this->storage();
        self::assertNull($storage->get('sessions', 'missing'));
        self::assertSame([], $storage->list('sessions'));
        $first = $storage->create('sessions', ['value' => 1.0]);
        $second = $storage->create('sessions', ['value' => 2]);
        self::assertNotSame($first, $second);
        self::assertNotSame('', $first);
        self::assertSame(['value' => 1.0], $storage->get('sessions', $first));
        self::assertSame('chosen', $storage->create('sessions', [], 'chosen'));
    }

    public function testDuplicateCreationPreservesValueAndOrder(): void
    {
        $storage = $this->storage();
        $storage->create('items', ['value' => 'original'], 'first');
        $storage->create('items', [], 'second');
        try {
            $storage->create('items', ['value' => 'replacement'], 'first');
            self::fail('Duplicate creation must fail.');
        } catch (DuplicateKeyException) {
            self::assertSame(['value' => 'original'], $storage->get('items', 'first'));
            self::assertSame(['first', 'second'], array_column($storage->list('items'), 'key'));
        }
    }

    public function testReplacementUpdateAndRecreationOrdering(): void
    {
        $storage = $this->storage();
        $storage->set('items', 'first', ['old' => true]);
        $storage->set('items', 'second', []);
        $storage->set('items', 'first', ['new' => true]);
        self::assertTrue($storage->update('items', 'first', [['path' => ['new'], 'value' => false]]));
        self::assertSame(['new' => false], $storage->get('items', 'first'));
        self::assertSame(['first', 'second'], array_column($storage->list('items'), 'key'));
        $storage->delete('items', 'first');
        $storage->create('items', [], 'first');
        self::assertSame(['second', 'first'], array_column($storage->list('items'), 'key'));
    }

    public function testExactStringKeysAndLatestLimits(): void
    {
        $storage = $this->storage();
        foreach (['1', '01', '123', 'last'] as $key) {
            $storage->create('items', ['key' => $key], $key);
        }
        self::assertSame(['1', '01', '123', 'last'], array_column($storage->list('items'), 'key'));
        self::assertSame([
            ['key' => '123', 'value' => ['key' => '123']],
            ['key' => 'last', 'value' => ['key' => 'last']],
        ], $storage->list('items', 2));
        self::assertCount(4, $storage->list('items', 99));
        self::assertSame([], $storage->list('items', 0));
        $this->expectException(InvalidArgumentException::class);
        $storage->list('items', -1);
    }

    public function testNamespaceIsolationAndIdempotentCleanup(): void
    {
        $storage = $this->storage();
        $storage->set('one', 'same', ['value' => 1]);
        $storage->set('two', 'same', ['value' => 2]);
        $storage->delete('one', 'missing');
        $storage->delete('one', 'same');
        $storage->delete('one', 'same');
        self::assertNull($storage->get('one', 'same'));
        $storage->set('one', 'new', []);
        $storage->clear('one');
        $storage->clear('one');
        self::assertSame([], $storage->list('one'));
        self::assertSame(['value' => 2], $storage->get('two', 'same'));
    }

    public function testValuesAndReturnedSnapshotsDetachReferences(): void
    {
        $storage = $this->storage();
        $mutable = 'before';
        $value = ['nested' => ['value' => &$mutable]];
        $storage->create('items', $value, 'first');
        $storage->set('items', 'second', $value);
        $storage->update('items', 'second', [['path' => ['assigned'], 'value' => $value]]);
        $mutable = 'after';
        $snapshot = $storage->get('items', 'first');
        self::assertSame(['nested' => ['value' => 'before']], $snapshot);
        $reference = &$snapshot['nested'];
        $reference = ['changed' => true];
        $listed = $storage->list('items');
        $listed[0]['value'] = ['changed' => true];
        self::assertSame(['nested' => ['value' => 'before']], $storage->get('items', 'first'));
        self::assertSame([
            'nested' => ['value' => 'before'],
            'assigned' => ['nested' => ['value' => 'before']],
        ], $storage->get('items', 'second'));
    }

    public function testNestedAssignmentsLiteralPathsAndRemovals(): void
    {
        $storage = $this->storage();
        $storage->set('items', 'one', ['keep' => true, 'remove' => false, 'metadata' => ['old' => 1]]);
        $storage->update('items', 'one', [
            ['path' => ['metadata', 'new'], 'value' => null],
            ['path' => ['literal.dot'], 'value' => ['a', 'b']],
            ['path' => ['created', 'nested', 'field'], 'value' => 'yes'],
        ], [['remove'], ['metadata', 'old'], ['absent', 'nested']]);
        self::assertSame([
            'keep' => true,
            'metadata' => ['new' => null],
            'literal.dot' => ['a', 'b'],
            'created' => ['nested' => ['field' => 'yes']],
        ], $storage->get('items', 'one'));
        $before = $storage->list('items');
        self::assertTrue($storage->update('items', 'one'));
        self::assertTrue($storage->update('items', 'one', [['path' => ['keep'], 'value' => true]]));
        self::assertSame($before, $storage->list('items'));
    }

    public function testMissingUpdateNeverCreatesAnEntry(): void
    {
        $storage = $this->storage();
        self::assertFalse($storage->update('items', 'absent'));
        self::assertFalse($storage->update('items', 'absent', [['path' => ['field'], 'value' => true]]));
        self::assertNull($storage->get('items', 'absent'));
        self::assertSame([], $storage->list('items'));

        $storage->create('items', ['keep' => true], 'existing');
        self::assertFalse($storage->update('items', 'absent'));
        self::assertSame(['keep' => true], $storage->get('items', 'existing'));

        $storage->delete('items', 'existing');
        self::assertFalse($storage->update('items', 'existing'));
        self::assertSame([], $storage->list('items'));
    }

    public function testInvalidUpdateRequestsAreAtomic(): void
    {
        $storage = $this->storage();
        $original = ['scalar' => 1, 'list' => ['a', 'b'], 'keep' => true];
        $storage->set('items', 'one', $original);
        $requests = [
            [[['path' => [], 'value' => 1]], []],
            [[['path' => ['keep'], 'value' => false], ['path' => ['scalar', 'nested'], 'value' => 1]], []],
            [[['path' => ['list', '0'], 'value' => 1]], []],
            [[['path' => ['parent'], 'value' => []], ['path' => ['parent', 'child'], 'value' => 1]], []],
            [[['path' => ['same'], 'value' => 1]], [['same']]],
            [[['path' => ['same'], 'value' => 1], ['path' => ['same'], 'value' => 2]], []],
            [[], [['parent'], ['parent', 'child']]],
            [[], [['scalar', 'child']]],
            [[], [['list', '0']]],
            [[['path' => ['keep'], 'value' => new stdClass()]], []],
        ];
        foreach ($requests as [$assignments, $removals]) {
            try {
                $storage->update('items', 'one', $assignments, $removals);
                self::fail('An invalid update must fail.');
            } catch (InvalidArgumentException) {
                self::assertSame($original, $storage->get('items', 'one'));
            }
        }
    }

    public function testInvalidValuesAreRejectedBeforeChangingState(): void
    {
        $storage = $this->storage();
        $storage->set('items', 'one', ['valid' => true]);
        $recursive = [];
        $recursive['self'] = &$recursive;
        foreach ([['object' => new stdClass()], ['number' => INF], ['utf8' => "\xff"], $recursive] as $invalid) {
            try {
                $storage->set('items', 'one', $invalid);
                self::fail('Invalid values must fail.');
            } catch (InvalidArgumentException) {
                self::assertSame(['valid' => true], $storage->get('items', 'one'));
            }
            try {
                $storage->create('items', $invalid, 'new');
                self::fail('Invalid values must fail.');
            } catch (InvalidArgumentException) {
                self::assertNull($storage->get('items', 'new'));
            }
        }
    }

    public function testJsonDepthBoundarySupportsCreateSetAndUpdate(): void
    {
        $storage = $this->storage();
        $deep = self::nestedValue(511);
        $storage->create('depth', $deep, 'created');
        $storage->set('depth', 'set', ['before' => true]);
        $storage->set('depth', 'set', $deep);
        $storage->set('depth', 'updated', []);
        $updated = ['field' => self::nestedValue(510)];
        $storage->update('depth', 'updated', [['path' => ['field'], 'value' => self::nestedValue(510)]]);
        self::assertSame($deep, $storage->get('depth', 'created'));
        self::assertSame($deep, $storage->get('depth', 'set'));
        self::assertSame($updated, $storage->get('depth', 'updated'));
        self::assertSame([
            ['key' => 'created', 'value' => $deep],
            ['key' => 'set', 'value' => $deep],
            ['key' => 'updated', 'value' => $updated],
        ], $storage->list('depth'));
    }

    public function testExcessiveJsonDepthFailsAtomically(): void
    {
        $storage = $this->storage();
        $storage->create('depth', ['original' => true], 'existing');
        $before = $storage->list('depth');
        $operations = [
            fn() => $storage->create('depth', self::nestedValue(512), 'rejected'),
            fn() => $storage->set('depth', 'existing', self::nestedValue(512)),
            fn() => $storage->update('depth', 'existing', [['path' => ['field'], 'value' => self::nestedValue(511)]]),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Values beyond the JSON depth limit must fail.');
            } catch (InvalidArgumentException) {
                self::assertSame($before, $storage->list('depth'));
            }
        }
    }

    /** @return array<string, mixed> */
    protected static function nestedValue(int $levels): array
    {
        $value = [];
        for ($level = 0; $level < $levels; ++$level) {
            $value = ['nested' => $value];
        }
        return $value;
    }

    public function testIdentifiersAreValidatedForEveryOperation(): void
    {
        $storage = $this->storage();
        foreach (['', '.', '..', '../escape', '/absolute', 'space name', "nul\0byte"] as $invalid) {
            $operations = [
                fn() => $storage->create($invalid, []),
                fn() => $storage->create('items', [], $invalid),
                fn() => $storage->get($invalid, 'key'),
                fn() => $storage->get('items', $invalid),
                fn() => $storage->set($invalid, 'key', []),
                fn() => $storage->set('items', $invalid, []),
                fn() => $storage->update($invalid, 'key'),
                fn() => $storage->update('items', $invalid),
                fn() => $storage->delete($invalid, 'key'),
                fn() => $storage->delete('items', $invalid),
                fn() => $storage->list($invalid),
                fn() => $storage->clear($invalid),
            ];
            foreach ($operations as $operation) {
                try {
                    $operation();
                    self::fail('An invalid identifier must fail.');
                } catch (InvalidArgumentException) {
                    self::assertSame([], $storage->list('items'));
                }
            }
        }
    }
}
