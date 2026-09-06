<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Storage;

use InvalidArgumentException;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class InMemoryStorageTest extends TestCase
{
    public function testStoredMetadataAreDetachedFromExternalReferences(): void
    {
        $storage = new InMemoryStorage();
        $project = 'created';
        $created = $storage->create('metadata', [], ['project' => &$project]);
        $project = 'external change';
        self::assertSame(['project' => 'created'], $created->metadata);
        self::assertSame(['project' => 'created'], $storage->read('metadata', $created->key)?->metadata);
        self::assertCount(1, iterator_to_array($storage->entries('metadata', ['project' => 'created'])));

        $written = $storage->write('metadata', $created->key, [], ['project' => &$project]);
        $project = 'another external change';
        self::assertSame(['project' => 'external change'], $written->metadata);
        self::assertSame(['project' => 'external change'], $storage->read('metadata', $created->key)->metadata);
        self::assertCount(1, iterator_to_array($storage->entries('metadata', ['project' => 'external change'])));
        self::assertSame([], iterator_to_array($storage->entries('metadata', ['project' => 'another external change'])));
    }

    public function testSuppliedKeysCannotBeCreatedTwice(): void
    {
        $storage = new InMemoryStorage();
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
        $storage = new InMemoryStorage();
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

    public function testNumericStringKeysRemainStringsWhenListed(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('demo', '123', ['value' => 'numeric key']);

        $entries = iterator_to_array($storage->entries('demo'));

        self::assertCount(1, $entries);
        self::assertSame('123', $entries[0]->key);
        self::assertSame(['value' => 'numeric key'], $entries[0]->data);
        self::assertEquals($storage->read('demo', '123'), $entries[0]);
    }

    public function testCreateReturnsANewOpaqueKeyAndStoredDocument(): void
    {
        $storage = new InMemoryStorage();
        $first = $storage->create('sessions', ['value' => 'one']);
        $second = $storage->create('sessions', ['value' => 'two']);

        self::assertNotSame($first->key, $second->key);
        self::assertSame(['value' => 'one'], $first->data);
        self::assertSame(
            $first->data,
            $storage->read('sessions', $first->key)?->data,
        );
    }

    public function testAMissingDocumentIsNull(): void
    {
        self::assertNull((new InMemoryStorage())->read('history', 'current'));
    }

    public function testDocumentsAreIsolatedByNamespaceAndKey(): void
    {
        $storage = new InMemoryStorage();

        $storage->write('sessions', 'first', ['value' => 'one']);
        $storage->write('sessions', 'second', ['value' => 'two']);
        $storage->write('input-history', 'first', ['value' => 'three']);

        self::assertSame(
            ['value' => 'one'],
            $storage->read('sessions', 'first')?->data,
        );
        self::assertSame(
            ['value' => 'two'],
            $storage->read('sessions', 'second')?->data,
        );
        self::assertSame(
            ['value' => 'three'],
            $storage->read('input-history', 'first')?->data,
        );
    }

    public function testWritingAnExistingValueReplacesIt(): void
    {
        $storage = new InMemoryStorage();
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
    }

    public function testEntriesExposeLogicalKeysAndJsonDocumentBehaviour(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('sessions', 'first', ['value' => 'one']);
        $storage->write('sessions', 'second', ['value' => 'two']);

        $entries = iterator_to_array($storage->entries('sessions'));

        self::assertSame(['first', 'second'], array_column($entries, 'key'));
        self::assertSame(
            strlen('{"value":"two"}'),
            $entries[1]->size(),
        );
    }

    public function testMetadataRoundTripsAndDeleteIsIdempotent(): void
    {
        $storage = new InMemoryStorage();
        $document = $storage->create(
            'sessions',
            ['value'],
            ['lastUsedAt' => '2026-09-03T12:00:00+00:00'],
        );

        self::assertSame(
            ['lastUsedAt' => '2026-09-03T12:00:00+00:00'],
            $document->metadata,
        );

        $storage->delete('sessions', $document->key);
        $storage->delete('sessions', $document->key);

        self::assertNull($storage->read('sessions', $document->key));
    }

    public function testMetadataNamesMustBePortable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new InMemoryStorage())->write(
            'sessions',
            'known',
            ['value'],
            ['LastUsedAt' => '2026-09-03T12:00:00+00:00'],
        );
    }
}
