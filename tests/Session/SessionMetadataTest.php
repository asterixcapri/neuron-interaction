<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Session\SessionMessageStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Session\SessionSummary;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function glob;
use function is_dir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

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
        $initial = (new SessionStore($files ? new FileStorage($this->directory) : $storage, 'alice'))->get($session->getKey());
        self::assertNotNull($initial);
        self::assertSame($session->getMetadata(), $initial->getMetadata());
        $messageStore = new SessionMessageStore($storage, 'sessions', 'alice');
        $messageStore->append($session->getKey(), new UserMessage('Opening title'));
        $lastUsed = $store->list()[0]->getLastUsedAt();
        $session->setMetadata('projectId', 'beta');
        $session->setMetadata('branchName', 'main');
        $session->removeMetadata('userId');
        $session->removeMetadata('absent');
        self::assertEquals($lastUsed, $store->list()[0]->getLastUsedAt());

        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, 'alice');
        $reopened = $fresh->get($session->getKey());
        self::assertNotNull($reopened);
        self::assertSame('alice', $reopened->getUserId());
        self::assertSame(['projectId' => 'beta', 'lastUsedAt' => 'application value', 'branchName' => 'main'], $reopened->getMetadata());
        self::assertSame('Opening title', $reopened->getMessages()[0]->getContent());
        self::assertNull((new SessionStore($storage, 'bob'))->get($session->getKey()));
        self::assertCount(1, $fresh->list(['lastUsedAt' => 'application value']));
    }

    #[DataProvider('storageKinds')]
    public function testAppendingArchivingAndClearingPreserveMetadata(bool $files): void
    {
        $storage = $this->storage($files);
        $store = new SessionStore($storage, 'alice');
        $session = $store->create(['projectId' => 'alpha']);
        $messages = new SessionMessageStore($storage, 'sessions', 'alice');
        $messages->append($session->getKey(), new UserMessage('Old question'));
        $messages->append($session->getKey(), new AssistantMessage('Old answer'));
        $question = 'Next question';
        $messages->append($session->getKey(), new UserMessage($question));
        $messages->archive($session->getKey(), 2);
        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, 'alice');
        $reopened = $fresh->get($session->getKey());
        self::assertNotNull($reopened);
        self::assertSame(['projectId' => 'alpha'], $reopened->getMetadata());
        self::assertCount(3, $reopened->getMessages());
        self::assertCount(1, $messages->loadActive($session->getKey()));
        self::assertSame($question, $reopened->getMessages()[2]->getContent());
        $messages->clear($reopened->getKey());
        $cleared = $fresh->get($session->getKey());
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
        $messageStore = new SessionMessageStore($storage, 'sessions', 'alice');
        $messageStore->append($first->getKey(), new UserMessage('First title'));
        $first->setTitle('First title');
        $second = $store->create(['projectId' => 'alpha', 'branchName' => 'main']);
        $messageStore->append($second->getKey(), new UserMessage('Second title'));
        $second->setTitle('Second title');
        $store->create(['projectId' => 'alpha', 'branchName' => 'main']);
        $messageStore->append($store->create(['projectId' => 'alpha'])->getKey(), new UserMessage('Missing branch'));
        $messageStore->append($store->create(['projectId' => 'alpha', 'branchName' => 'Main'])->getKey(), new UserMessage('Different case'));
        $messageStore->append($store->create(['projectId' => 'alpha', 'branchName' => 'main '])->getKey(), new UserMessage('Trailing space'));
        (new SessionMessageStore($storage, 'sessions', 'bob'))->append((new SessionStore($storage, 'bob'))->create(['projectId' => 'alpha', 'branchName' => 'main', 'userId' => 'bob'])->getKey(), new UserMessage('Another owner'));
        $messageStore->append($first->getKey(), new AssistantMessage('Latest answer'));
        $filter = ['projectId' => 'alpha', 'branchName' => 'main'];
        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, 'alice');
        self::assertSame(['First title', 'Second title'], array_map(static fn(SessionSummary $summary): ?string => $summary->getTitle(), $fresh->list($filter)));
        self::assertSame([], $fresh->list(['missingKey' => 'value']));
        self::assertCount(1, $fresh->list(['userId' => 'bob']));
        self::assertCount(5, $fresh->list([]));
        self::assertEquals($fresh->list(), $fresh->list([]));
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
