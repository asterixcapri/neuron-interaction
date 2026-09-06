<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Session\SessionSummary;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionMetadataTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/session-metadata-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/sessions/*.json') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($this->directory . '/sessions')) {
            rmdir($this->directory . '/sessions');
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /** @return array<string, array{bool}> */
    public static function storageKinds(): array
    {
        return ['memory' => [false], 'files' => [true]];
    }

    private function storage(bool $files): StorageInterface
    {
        return $files ? new FileStorage($this->directory) : new InMemoryStorage();
    }

    #[DataProvider('storageKinds')]
    public function testMetadataEditsPersistAndPreserveHistoryAndSystemFields(bool $files): void
    {
        $storage = $this->storage($files);
        $store = new SessionStore($storage, 'alice');
        $session = $store->create(['projectId' => 'alpha', 'userId' => 'bob', 'lastUsedAt' => 'application value']);
        self::assertSame(['projectId' => 'alpha', 'userId' => 'bob', 'lastUsedAt' => 'application value'], $session->getMetadata());
        $initial = (new SessionStore($files ? new FileStorage($this->directory) : $storage, 'alice'))->read($session->getKey());
        self::assertNotNull($initial);
        self::assertSame($session->getMetadata(), $initial->getMetadata());
        $session->addMessage(new UserMessage('Opening title'));
        $lastUsed = $store->summaries()[0]->lastUsedAt;
        $session->setMetadata('projectId', 'beta');
        $session->setMetadata('branchName', 'main');
        $session->removeMetadata('userId');
        $session->removeMetadata('absent');
        self::assertEquals($lastUsed, $store->summaries()[0]->lastUsedAt);

        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, 'alice');
        $reopened = $fresh->read($session->getKey());
        self::assertNotNull($reopened);
        self::assertSame('alice', $reopened->getUserId());
        self::assertSame(['projectId' => 'beta', 'lastUsedAt' => 'application value', 'branchName' => 'main'], $reopened->getMetadata());
        self::assertSame('Opening title', $reopened->getMessages()[0]->getContent());
        self::assertNull((new SessionStore($storage, 'bob'))->read($session->getKey()));
        self::assertCount(1, $fresh->summaries(['lastUsedAt' => 'application value']));
    }

    #[DataProvider('storageKinds')]
    public function testHistoryAddingTrimmingAndClearingPreserveMetadata(bool $files): void
    {
        $storage = $this->storage($files);
        $store = new SessionStore($storage, 'alice');
        $session = $store->create(['projectId' => 'alpha']);
        $session->addMessage(new UserMessage('Old question'));
        $session->addMessage((new AssistantMessage('Old answer'))->setUsage(new Usage(48000, 1000)));
        $question = str_repeat('Next question ', 2000);
        $session->addMessage(new UserMessage($question));
        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, 'alice');
        $reopened = $fresh->read($session->getKey());
        self::assertNotNull($reopened);
        self::assertSame(['projectId' => 'alpha'], $reopened->getMetadata());
        self::assertCount(1, $reopened->getMessages());
        self::assertSame($question, $reopened->getMessages()[0]->getContent());
        $reopened->flushAll();
        $cleared = $fresh->read($session->getKey());
        self::assertNotNull($cleared);
        self::assertSame([], $cleared->getMessages());
        self::assertSame(['projectId' => 'alpha'], $cleared->getMetadata());
        self::assertSame('alice', $cleared->getUserId());
    }

    #[DataProvider('storageKinds')]
    public function testFiltersUseExactAndMatchingWithinTheOwnerAndRetainSummaryRules(bool $files): void
    {
        $storage = $this->storage($files);
        $store = new SessionStore($storage, 'alice');
        $first = $store->create(['projectId' => 'alpha', 'branchName' => 'main', 'extra' => 'allowed', 'userId' => 'bob']);
        $first->addMessage(new UserMessage('First title'));
        $second = $store->create(['projectId' => 'alpha', 'branchName' => 'main']);
        $second->addMessage(new UserMessage('Second title'));
        $store->create(['projectId' => 'alpha', 'branchName' => 'main']);
        $store->create(['projectId' => 'alpha'])->addMessage(new UserMessage('Missing branch'));
        $store->create(['projectId' => 'alpha', 'branchName' => 'Main'])->addMessage(new UserMessage('Different case'));
        $store->create(['projectId' => 'alpha', 'branchName' => 'main '])->addMessage(new UserMessage('Trailing space'));
        (new SessionStore($storage, 'bob'))->create(['projectId' => 'alpha', 'branchName' => 'main', 'userId' => 'bob'])->addMessage(new UserMessage('Another owner'));
        $first->addMessage(new AssistantMessage('Latest answer'));
        $filter = ['projectId' => 'alpha', 'branchName' => 'main'];
        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, 'alice');
        self::assertSame(['First title', 'Second title'], array_map(static fn (SessionSummary $summary): string => $summary->title, $fresh->summaries($filter)));
        self::assertSame([], $fresh->summaries(['missingKey' => 'value']));
        self::assertCount(1, $fresh->summaries(['userId' => 'bob']));
        self::assertCount(5, $fresh->summaries([]));
        self::assertEquals($fresh->summaries(), $fresh->summaries([]));
    }

    public function testInvalidMetadataNamesAreRejectedBeforeWriting(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'alice');
        $session = $store->create(['validKey' => 'value']);
        try {
            $session->setMetadata('invalid_key', 'bad');
            self::fail('Invalid metadata name accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(['validKey' => 'value'], $session->getMetadata());
        }
        $this->expectException(InvalidArgumentException::class);
        $store->create(['InvalidKey' => 'bad']);
    }
}
