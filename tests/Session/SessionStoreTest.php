<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Tests\History\SessionHistory;
use NeuronInteraction\Tests\History\SessionMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use function array_map;
use function array_unique;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sort;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;

final class SessionStoreTest extends TestCase
{
    #[DataProvider('storageKinds')]
    public function testContentVisibilityAndExactMetadataFiltersFollowLifecycle(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $store = new SessionStore($storage, $nativeMessages, 'alice');
        $session = $store->create(['project' => 'one', 'environment' => 'test']);
        $other = $store->create(['project' => 'One', 'environment' => 'test']);
        $messages = new SessionMessages($storage, $nativeMessages, 'alice');
        self::assertSame([], $store->list());
        $messages->append($session->getKey(), new AssistantMessage('Introductory answer'));
        self::assertCount(0, $store->list());
        $messages->append($session->getKey(), new UserMessage('My topic'));
        $messages->append($other->getKey(), new UserMessage('Other topic'));
        self::assertCount(2, $store->list());
        self::assertSame([$session->getKey()], array_map(
            static fn(Session $summary): string => $summary->getKey(),
            $store->list(['project' => 'one', 'environment' => 'test']),
        ));
        self::assertSame([], $store->list(['project' => 'one', 'missing' => '']));
        $messages->archive($session->getKey(), 2);
        self::assertCount(1, $store->list(['project' => 'one']));
        self::assertSame([], $messages->loadActive($session->getKey()));
        $resumed = $store->get($session->getKey());
        self::assertNotNull($resumed);
        self::assertCount(2, $resumed->getMessages());
        $messages->clear($session->getKey());
        self::assertSame([], $store->list(['project' => 'one']));
        self::assertNotNull($store->get($session->getKey()));
        $messages->append($session->getKey(), new UserMessage('New content'));
        self::assertCount(1, $store->list(['project' => 'one']));
    }

    #[DataProvider('storageKinds')]
    public function testListingDoesNotConsultAnyMessageHistory(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $messages = $this->createMock(MessageStoreInterface::class);
        foreach (['loadActive', 'loadAll', 'append', 'archive', 'clear'] as $method) {
            $messages->expects(self::never())->method($method);
        }
        $store = new SessionStore($storage, $messages, 'alice');
        $session = $store->create(['project' => 'one']);
        $session->setTitle('Record title');
        $storage->update(Session::namespaceForUser('alice'), $session->getKey(), [['path' => ['hasContent'], 'value' => true]]);
        $listed = $store->list(['project' => 'one']);
        self::assertCount(1, $listed);
        self::assertSame($session->getKey(), $listed[0]->getKey());
        self::assertSame('Record title', $listed[0]->getTitle());
        self::assertEquals($session->getLastUsedAt(), $listed[0]->getLastUsedAt());
    }

    public function testListingDecodesItsStorageSnapshotWithoutAdditionalReads(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::never())->method('get');
        $storage->expects(self::once())->method('list')->with(Session::namespaceForUser('alice'))->willReturn([
            ['key' => 'saved', 'value' => [
                'title' => 'Snapshot title',
                'createdAt' => '2026-09-04T12:00:00.000000+00:00',
                'lastUsedAt' => '2026-09-05T12:00:00.000000+00:00',
                'hasContent' => true,
                'metadata' => ['project' => 'one'],
            ]],
        ]);
        $summaries = (new SessionStore($storage, $nativeMessages, 'alice'))->list(['project' => 'one']);
        self::assertCount(1, $summaries);
        self::assertSame('Snapshot title', $summaries[0]->getTitle());
        self::assertSame('2026-09-05', $summaries[0]->getLastUsedAt()->format('Y-m-d'));
    }

    #[DataProvider('storageKinds')]
    public function testListingAndSessionReadsRejectTheSameCorruptRecordFields(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $store = new SessionStore($storage, $nativeMessages, 'alice');
        $session = $store->create();
        SessionHistory::append($session, new UserMessage('Content'));
        $record = $storage->get(Session::namespaceForUser('alice'), $session->getKey());
        self::assertNotNull($record);
        foreach (['title' => 12, 'createdAt' => 'invalid', 'lastUsedAt' => 'invalid', 'hasContent' => 1, 'metadata' => false] as $field => $invalid) {
            $corrupt = $record;
            $corrupt[$field] = $invalid;
            $storage->set(Session::namespaceForUser('alice'), $session->getKey(), $corrupt);
            try {
                $store->list();
                self::fail('Listing must reject invalid record fields.');
            } catch (UnexpectedValueException $listed) {
                try {
                    $store->get($session->getKey());
                    self::fail('Session reads must reject invalid record fields.');
                } catch (UnexpectedValueException $read) {
                    self::assertSame($listed->getMessage(), $read->getMessage());
                }
            }
        }
    }

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/neuron-interaction-sessions-'
            . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testStartMintsDistinctStorageSafeKeysAndEmptyHistories(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $sessionStore = new SessionStore(new InMemoryStorage(), $nativeMessages, 'local-user');
        $first = $sessionStore->create();
        $second = $sessionStore->create();

        self::assertNotSame($first->getKey(), $second->getKey());
        self::assertSame([], $first->getMessages());
        self::assertSame([], $second->getMessages());

        SessionHistory::append($first, new UserMessage('First'));
        SessionHistory::append($second, new UserMessage('Second'));
        $keys = array_map(
            static fn(Session $session): string => $session->getKey(),
            $sessionStore->list(),
        );

        self::assertCount(2, array_unique($keys));

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $key);
        }
    }

    public function testAnEmptySessionIsKnownButNotListed(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $sessionStore = new SessionStore($storage, $nativeMessages, 'local-user');
        $key = $sessionStore->create()->getKey();

        self::assertSame([], $sessionStore->list());
        $resumed = $sessionStore->get($key);
        self::assertNotNull($resumed);
        self::assertSame($key, $resumed->getKey());
        self::assertSame([], $resumed->getMessages());
    }

    #[DataProvider('storageKinds')]
    public function testSessionsCanBeListedAndResumedByANewInstance(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files
            ? new FileStorage($this->directory)
            : new InMemoryStorage();
        $history = (new SessionStore($storage, $nativeMessages, 'local-user'))->create();
        SessionHistory::append($history, new UserMessage('Written earlier'));
        $history->setTitle('Written earlier');
        $reopened = new SessionStore($files
            ? new FileStorage($this->directory)
            : $storage, $nativeMessages, 'local-user');
        $listed = $reopened->list();

        self::assertCount(1, $listed);
        self::assertSame('Written earlier', $listed[0]->getTitle());
        $session = $reopened->get($listed[0]->getKey());
        self::assertNotNull($session);
        self::assertSame('Written earlier', $session->getMessages()[0]->getContent());
    }

    /** @return array<string, array{bool}> */
    public static function storageKinds(): array
    {
        return ['memory' => [false], 'files' => [true]];
    }

    public function testSummariesUseTheOpeningWordsAndMostRecentUseOrder(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $sessionStore = new SessionStore(new InMemoryStorage(), $nativeMessages, 'local-user');
        $first = $sessionStore->create();
        SessionHistory::append($first, new UserMessage('The older subject'));
        $first->setTitle('The older subject');
        SessionHistory::append($first, new AssistantMessage('An answer'));
        $second = $sessionStore->create();
        SessionHistory::append($second, new UserMessage('The newer subject'));
        $second->setTitle('The newer subject');

        self::assertSame(
            ['The newer subject', 'The older subject'],
            array_map(
                static fn(Session $session): ?string => $session->getTitle(),
                $sessionStore->list(),
            ),
        );

        SessionHistory::append($first, new UserMessage('A later question'));
        $listed = $sessionStore->list();

        self::assertSame('The older subject', $listed[0]->getTitle());
        self::assertGreaterThan($listed[1]->getLastUsedAt(), $listed[0]->getLastUsedAt());
    }

    public function testUnknownKeysAreRejectedWithoutCreatingAHistory(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $sessionStore = new SessionStore($storage, $nativeMessages, 'local-user');

        self::assertNull($sessionStore->get('unknown'));

        self::assertNull($storage->get(Session::namespaceForUser('local-user'), 'unknown'));
        self::assertSame([], $storage->list(Session::namespaceForUser('local-user')));
    }

    public function testUserContentIsListedWithoutDerivingATitle(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $sessionStore = new SessionStore(new InMemoryStorage(), $nativeMessages, 'local-user');
        $history = $sessionStore->create();
        SessionHistory::append($history, new UserMessage(" \n\t"));
        SessionHistory::append($history, new AssistantMessage('An introductory answer'));
        SessionHistory::append($history, new UserMessage([
            new ReasoningContent('Internal reasoning'),
            new ImageContent('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', SourceType::BASE64, 'image/png'),
        ]));

        $withoutText = $sessionStore->list();
        self::assertCount(1, $withoutText);
        self::assertNull($withoutText[0]->getTitle());

        SessionHistory::append($history, new AssistantMessage('Image received'));
        $title = "  A\x00 title\nwith \x1b[31mcolor\x1b[0m "
            . str_repeat('long words ', 30);
        SessionHistory::append($history, new UserMessage([
            new ImageContent('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', SourceType::BASE64, 'image/png'),
            new TextContent($title),
        ]));
        SessionHistory::append($history, new AssistantMessage('An answer'));
        SessionHistory::append($history, new UserMessage('Another subject'));

        self::assertNull($sessionStore->list()[0]->getTitle());
    }

    public function testFileOnlySessionUsesItsFilenameAndCanBeResumed(): void
    {
        $nativeMessages = new FileMessageStore($this->directory . '/messages');
        $storage = new FileStorage($this->directory);
        $store = new SessionStore($storage, $nativeMessages, 'local-user');
        $session = $store->create();
        SessionHistory::append($session, new UserMessage(new FileContent('https://example.com/report.pdf', SourceType::URL, 'application/pdf', 'report.pdf')));
        $reopened = new SessionStore(new FileStorage($this->directory), $nativeMessages, 'local-user');
        $listed = $reopened->list();

        self::assertCount(1, $listed);
        self::assertNull($listed[0]->getTitle());
        $resumed = $reopened->get($listed[0]->getKey());
        self::assertNotNull($resumed);
        self::assertInstanceOf(FileContent::class, $resumed->getMessages()[0]->getContentBlocks()[0]);
    }

    #[DataProvider('storageKinds')]
    public function testEqualLastUseTimesAreOrderedByKey(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $sessionStore = new SessionStore($storage, $nativeMessages, 'local-user');
        SessionHistory::append($sessionStore->create(), new UserMessage('First'));
        SessionHistory::append($sessionStore->create(), new UserMessage('Second'));
        $keys = [];

        foreach ($storage->list(Session::namespaceForUser('local-user')) as $entry) {
            $keys[] = $entry['key'];
            $storage->update(Session::namespaceForUser('local-user'), $entry['key'], [
                ['path' => ['lastUsedAt'], 'value' => '2026-09-04T12:00:00.000000+00:00'],
            ]);
        }

        sort($keys);

        self::assertSame($keys, array_map(
            static fn(Session $session): string => $session->getKey(),
            $sessionStore->list(),
        ));
    }

    public function testFileSummaryRetainsIdentityTitleAndLastUse(): void
    {
        $nativeMessages = new FileMessageStore($this->directory . '/messages');
        $sessionStore = new SessionStore(new FileStorage($this->directory), $nativeMessages, 'local-user');
        $history = $sessionStore->create();
        SessionHistory::append($history, new UserMessage('Stored in a file'));
        $history->setTitle('Stored in a file');
        $reopened = new SessionStore(new FileStorage($this->directory), $nativeMessages, 'local-user');
        $listed = $reopened->list();

        self::assertCount(1, $listed);
        self::assertSame($history->getKey(), $listed[0]->getKey());
        self::assertSame('Stored in a file', $listed[0]->getTitle());
    }

    public function testLegacyFilesAreNeitherDiscoveredNorMigrated(): void
    {
        $nativeMessages = new FileMessageStore($this->directory . '/messages');
        mkdir($this->directory, recursive: true);
        $legacy = $this->directory . '/neuron_legacy-key.chat';
        file_put_contents($legacy, '[{"content":"Old"}]');
        $contents = file_get_contents($legacy);
        $sessionStore = new SessionStore(new FileStorage($this->directory), $nativeMessages, 'local-user');

        self::assertSame([], $sessionStore->list());

        self::assertNull($sessionStore->get('legacy-key'));

        self::assertFileExists($legacy);
        self::assertSame($contents, file_get_contents($legacy));
        self::assertFileDoesNotExist(
            $this->directory . '/sessions/legacy-key.json',
        );
    }

    #[DataProvider('storageKinds')]
    public function testOwnershipScopesCreationReadsSummariesAndDeletion(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $alice = new SessionStore($storage, $nativeMessages, 'alice@example.com');
        $bob = new SessionStore($storage, $nativeMessages, 'bob / local');
        $session = $alice->create();
        $key = $session->getKey();

        self::assertSame('alice@example.com', $session->getUserId());
        self::assertNotNull($alice->get($key));
        self::assertNull($bob->get($key));
        SessionHistory::append($session, new UserMessage('Alice conversation'));
        self::assertSame([], $bob->list());
        self::assertCount(1, $alice->list());
        $bob->delete($key);
        self::assertNotNull($alice->get($key));

        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, $nativeMessages, 'alice@example.com');
        $reopened = $fresh->get($key);
        self::assertNotNull($reopened);
        self::assertSame('alice@example.com', $reopened->getUserId());
        self::assertSame('Alice conversation', $reopened->getMessages()[0]->getContent());
        $fresh->delete($key);
        $fresh->delete($key);
        self::assertNull($alice->get($key));
        self::assertSame([], $alice->list());
    }

    public function testOwnerlessDocumentsAreNotAssignedToTheStoreUser(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $key = $storage->create('sessions', []);
        $sessionStore = new SessionStore($storage, $nativeMessages, 'local-user');
        self::assertNull($sessionStore->get($key));
        self::assertSame([], $sessionStore->list());
        $sessionStore->delete($key);
        self::assertNotNull($storage->get('sessions', $key));
    }

    public function testTitlesPersistIndependentlyFromMessagesAndLastUseTime(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $store = new SessionStore($storage, $nativeMessages, 'local-user');
        $session = $store->create();
        self::assertNull($session->getTitle());
        SessionHistory::append($session, new UserMessage('ciao'));
        $before = $session->getMessages();
        $lastUsed = $store->list()[0]->getLastUsedAt();
        $reopened = $store->get($session->getKey());
        self::assertNotNull($reopened);

        $reopened->setTitle('  Configurazione Redis  ');

        self::assertNull($session->getTitle());
        self::assertSame('Configurazione Redis', $reopened->getTitle());
        self::assertSame('Configurazione Redis', $store->list()[0]->getTitle());
        self::assertEquals($lastUsed, $store->list()[0]->getLastUsedAt());
        self::assertEquals($before, $session->getMessages());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
