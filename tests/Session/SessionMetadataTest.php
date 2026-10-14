<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use InvalidArgumentException;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Tests\History\SessionMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function is_dir;
use function is_link;
use function random_bytes;
use function rmdir;
use function scandir;
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
        $this->removeDirectory($this->directory);
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
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $this->storage($files);
        $store = new SessionStore($storage, $nativeMessages, 'alice');
        $session = $store->create(['projectId' => 'alpha', 'userId' => 'bob', 'lastUsedAt' => 'application value']);
        self::assertSame(['projectId' => 'alpha', 'userId' => 'bob', 'lastUsedAt' => 'application value'], $session->getMetadata());
        $initial = (new SessionStore($files ? new FileStorage($this->directory) : $storage, $nativeMessages, 'alice'))->get($session->getKey());
        self::assertNotNull($initial);
        self::assertSame($session->getMetadata(), $initial->getMetadata());
        $messageStore = new SessionMessages($storage, $nativeMessages, 'alice');
        $messageStore->append($session->getKey(), new UserMessage('Opening title'));
        $lastUsed = $store->list()[0]->getLastUsedAt();
        $session->setMetadata('projectId', 'beta');
        $session->setMetadata('branchName', 'main');
        $session->removeMetadata('userId');
        $session->removeMetadata('absent');
        self::assertEquals($lastUsed, $store->list()[0]->getLastUsedAt());

        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, $nativeMessages, 'alice');
        $reopened = $fresh->get($session->getKey());
        self::assertNotNull($reopened);
        self::assertSame('alice', $reopened->getUserId());
        self::assertSame(['projectId' => 'beta', 'lastUsedAt' => 'application value', 'branchName' => 'main'], $reopened->getMetadata());
        self::assertSame('Opening title', $reopened->getMessages()[0]->getContent());
        self::assertNull((new SessionStore($storage, $nativeMessages, 'bob'))->get($session->getKey()));
        self::assertCount(1, $fresh->list(['lastUsedAt' => 'application value']));
    }

    #[DataProvider('storageKinds')]
    public function testAppendingArchivingAndClearingPreserveMetadata(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $this->storage($files);
        $store = new SessionStore($storage, $nativeMessages, 'alice');
        $session = $store->create(['projectId' => 'alpha']);
        $messages = new SessionMessages($storage, $nativeMessages, 'alice');
        $messages->append($session->getKey(), new UserMessage('Old question'));
        $messages->append($session->getKey(), new AssistantMessage('Old answer'));
        $question = 'Next question';
        $messages->append($session->getKey(), new UserMessage($question));
        $messages->archive($session->getKey(), 2);
        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, $nativeMessages, 'alice');
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
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $this->storage($files);
        $store = new SessionStore($storage, $nativeMessages, 'alice');
        $first = $store->create(['projectId' => 'alpha', 'branchName' => 'main', 'extra' => 'allowed', 'userId' => 'bob']);
        $messageStore = new SessionMessages($storage, $nativeMessages, 'alice');
        $messageStore->append($first->getKey(), new UserMessage('First title'));
        $first->setTitle('First title');
        $second = $store->create(['projectId' => 'alpha', 'branchName' => 'main']);
        $messageStore->append($second->getKey(), new UserMessage('Second title'));
        $second->setTitle('Second title');
        $store->create(['projectId' => 'alpha', 'branchName' => 'main']);
        $messageStore->append($store->create(['projectId' => 'alpha'])->getKey(), new UserMessage('Missing branch'));
        $messageStore->append($store->create(['projectId' => 'alpha', 'branchName' => 'Main'])->getKey(), new UserMessage('Different case'));
        $messageStore->append($store->create(['projectId' => 'alpha', 'branchName' => 'main '])->getKey(), new UserMessage('Trailing space'));
        (new SessionMessages($storage, $nativeMessages, 'bob'))->append((new SessionStore($storage, $nativeMessages, 'bob'))->create(['projectId' => 'alpha', 'branchName' => 'main', 'userId' => 'bob'])->getKey(), new UserMessage('Another owner'));
        $messageStore->append($first->getKey(), new AssistantMessage('Latest answer'));
        $first->registerUse();
        $filter = ['projectId' => 'alpha', 'branchName' => 'main'];
        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, $nativeMessages, 'alice');
        self::assertSame(['First title', 'Second title'], array_map(static fn(Session $summary): ?string => $summary->getTitle(), $fresh->list($filter)));
        self::assertSame([], $fresh->list(['missingKey' => 'value']));
        self::assertCount(1, $fresh->list(['userId' => 'bob']));
        self::assertCount(5, $fresh->list([]));
        self::assertEquals($fresh->list(), $fresh->list([]));
    }

    #[DataProvider('storageKinds')]
    public function testMetadataNamesAreLiteralNonEmptyStrings(bool $files): void
    {
        $storage = $this->storage($files);
        $messages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $sessions = new SessionStore($storage, $messages, 'alice');
        $session = $sessions->create(['project_id' => 'solaro', 'Branch.Name' => 'main']);
        (new SessionMessages($storage, $messages, 'alice'))->append($session->getKey(), new UserMessage('Question'));
        $session->setMetadata('Branch.Name', 'feature');
        $session->setMetadata('label', '');

        self::assertSame(['project_id' => 'solaro', 'Branch.Name' => 'feature', 'label' => ''], $session->getMetadata());
        $listed = $sessions->list(['Branch.Name' => 'feature']);
        self::assertCount(1, $listed);
        self::assertSame($session->getMetadata(), $listed[0]->getMetadata());
        $session->removeMetadata('Branch.Name');
        self::assertSame(['project_id' => 'solaro', 'label' => ''], $session->getMetadata());
    }

    public function testInvalidMetadataNamesAreRejectedBeforeWriting(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'alice');
        $session = $store->create(['validKey' => 'value']);
        try {
            $session->setMetadata('', 'bad');
            self::fail('Invalid metadata name accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(['validKey' => 'value'], $session->getMetadata());
        }
        $this->expectException(InvalidArgumentException::class);
        $session->removeMetadata('');
    }
}
