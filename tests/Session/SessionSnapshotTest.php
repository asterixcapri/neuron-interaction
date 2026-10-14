<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SessionSnapshotTest extends TestCase
{
    public function testListingContainsCompleteInformationAndRemainsASnapshot(): void
    {
        $sessions = new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'alice');
        $session = $sessions->create(['project' => 'solaro', 'label' => '']);
        SessionHistory::append($session, new UserMessage('Question'));
        $session->setTitle('The opening words');
        $info = $sessions->list()[0];

        self::assertInstanceOf(Session::class, $info);
        self::assertSame($session->getKey(), $info->getKey());
        self::assertSame('The opening words', $info->getTitle());
        self::assertEquals($session->getCreatedAt(), $info->getCreatedAt());
        self::assertEquals($session->getLastUsedAt(), $info->getLastUsedAt());
        self::assertTrue($info->hasContent());
        self::assertSame('solaro', $info->getMetadataValue('project'));
        self::assertSame('', $info->getMetadataValue('label'));
        self::assertNull($info->getMetadataValue('missing'));
        self::assertSame('', $session->getMetadataValue('label'));
        self::assertNull($session->getMetadataValue('missing'));
        self::assertSame(['project' => 'solaro', 'label' => ''], $info->getMetadata());

        $session->setTitle('Updated title');
        $session->setMetadata('project', 'another');
        $metadata = $info->getMetadata();
        $metadata['project'] = 'local change';

        self::assertSame('The opening words', $info->getTitle());
        self::assertSame(['project' => 'solaro', 'label' => ''], $info->getMetadata());
        self::assertSame('solaro', $info->getMetadataValue('project'));
        self::assertSame('another', $session->getMetadataValue('project'));
        self::assertSame('Updated title', $sessions->list()[0]->getTitle());
        self::assertSame(['project' => 'another', 'label' => ''], $sessions->list()[0]->getMetadata());
    }
    public function testGetLoadsOnceAndDataGettersUseTheLoadedFields(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())->method('get')->with(Session::namespaceForUser('alice'), 'saved')->willReturn([
            'title' => 'Saved title',
            'createdAt' => '2026-09-04T12:00:00.000000+00:00',
            'lastUsedAt' => '2026-09-05T12:00:00.000000+00:00',
            'hasContent' => true,
            'metadata' => ['project' => 'one'],
        ]);
        $session = (new SessionStore($storage, new InMemoryMessageStore(), 'alice'))->get('saved');
        self::assertNotNull($session);
        for ($read = 0; $read < 2; ++$read) {
            self::assertSame('Saved title', $session->getTitle());
            self::assertSame('2026-09-04', $session->getCreatedAt()->format('Y-m-d'));
            self::assertSame('2026-09-05', $session->getLastUsedAt()->format('Y-m-d'));
            self::assertTrue($session->hasContent());
            self::assertSame(['project' => 'one'], $session->getMetadata());
            self::assertSame('one', $session->getMetadataValue('project'));
        }
    }

    public function testFailedWriteLeavesLocalFieldsUnchanged(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('get')->willReturn([
            'title' => 'Original',
            'createdAt' => '2026-09-04T12:00:00.000000+00:00',
            'lastUsedAt' => '2026-09-05T12:00:00.000000+00:00',
            'hasContent' => false,
            'metadata' => ['project' => 'one'],
        ]);
        $storage->expects(self::exactly(4))->method('update')->willThrowException(new RuntimeException('Write failed'));
        $session = (new SessionStore($storage, new InMemoryMessageStore(), 'alice'))->get('saved');
        self::assertNotNull($session);
        $lastUsedAt = $session->getLastUsedAt();
        foreach ([
            static fn() => $session->setTitle('Changed'),
            static fn() => $session->setMetadata('project', 'two'),
            static fn() => $session->removeMetadata('project'),
            static fn() => $session->registerUse(),
        ] as $write) {
            try {
                $write();
                self::fail('Failed writes must propagate.');
            } catch (RuntimeException $error) {
                self::assertSame('Write failed', $error->getMessage());
            }
            self::assertSame('Original', $session->getTitle());
            self::assertSame(['project' => 'one'], $session->getMetadata());
            self::assertFalse($session->hasContent());
            self::assertSame($lastUsedAt, $session->getLastUsedAt());
        }
    }

    public function testMissingRecordLeavesLocalFieldsUnchanged(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, new InMemoryMessageStore(), 'alice');
        $session = $sessions->create(['project' => 'one']);
        $session->setTitle('Original');
        $session->registerUse();
        $lastUsedAt = $session->getLastUsedAt();
        $storage->delete(Session::namespaceForUser('alice'), $session->getKey());

        foreach ([
            static fn() => $session->setTitle('Changed'),
            static fn() => $session->setMetadata('project', 'two'),
            static fn() => $session->removeMetadata('project'),
            static fn() => $session->registerUse(),
            static fn() => $session->resetContent(),
        ] as $write) {
            try {
                $write();
                self::fail('A missing Session must not accept local changes.');
            } catch (RuntimeException $error) {
                self::assertSame('The Session no longer exists.', $error->getMessage());
            }
            self::assertSame('Original', $session->getTitle());
            self::assertSame(['project' => 'one'], $session->getMetadata());
            self::assertTrue($session->hasContent());
            self::assertSame($lastUsedAt, $session->getLastUsedAt());
            self::assertNull($sessions->get($session->getKey()));
        }
    }

    public function testListedSessionsCanPersistChanges(): void
    {
        $sessions = new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'alice');
        $original = $sessions->create(['project' => 'one']);
        $original->registerUse();
        $listed = $sessions->list()[0];
        $listed->setTitle('Updated');
        $listed->setMetadata('project', 'two');
        self::assertSame('Updated', $listed->getTitle());
        self::assertSame('two', $listed->getMetadataValue('project'));
        self::assertNull($original->getTitle());
        $loaded = $sessions->get($original->getKey());
        self::assertNotNull($loaded);
        self::assertSame('Updated', $loaded->getTitle());
        self::assertSame('two', $loaded->getMetadataValue('project'));
    }
}
