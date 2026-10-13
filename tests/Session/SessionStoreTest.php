<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionMessageStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Session\SessionSummary;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Tests\History\SessionHistory;
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
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $store = new SessionStore($storage, 'alice');
        $session = $store->create(['project' => 'one', 'environment' => 'test']);
        $other = $store->create(['project' => 'One', 'environment' => 'test']);
        $messages = new SessionMessageStore($storage, 'alice');
        self::assertSame([], $store->list());
        $messages->append($session->getKey(), new AssistantMessage('Introductory answer'));
        self::assertCount(0, $store->list());
        $messages->append($session->getKey(), new UserMessage('My topic'));
        $messages->append($other->getKey(), new UserMessage('Other topic'));
        self::assertCount(2, $store->list());
        self::assertSame([$session->getKey()], array_map(
            static fn(SessionSummary $summary): string => $summary->getKey(),
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
        $store = new SessionStore($storage, 'alice', $messages);
        $session = $store->create(['project' => 'one']);
        $session->setTitle('Record title');
        $session->registerUse(new UserMessage('Content stored elsewhere'));
        $listed = $store->list(['project' => 'one']);
        self::assertCount(1, $listed);
        self::assertSame($session->getKey(), $listed[0]->getKey());
        self::assertSame('Record title', $listed[0]->getTitle());
        self::assertEquals($session->getLastUsedAt(), $listed[0]->getLastUsedAt());
    }

    public function testListingDecodesItsStorageSnapshotWithoutAdditionalReads(): void
    {
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
        $summaries = (new SessionStore($storage, 'alice'))->list(['project' => 'one']);
        self::assertCount(1, $summaries);
        self::assertSame('Snapshot title', $summaries[0]->getTitle());
        self::assertSame('2026-09-05', $summaries[0]->getLastUsedAt()->format('Y-m-d'));
    }

    #[DataProvider('storageKinds')]
    public function testListingAndSessionReadsRejectTheSameCorruptRecordFields(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $store = new SessionStore($storage, 'alice');
        $session = $store->create();
        $session->registerUse(new UserMessage('Content'));
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
                    $session->getTitle();
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
        $sessionStore = new SessionStore(new InMemoryStorage(), 'local-user');
        $first = $sessionStore->create();
        $second = $sessionStore->create();

        self::assertNotSame($first->getKey(), $second->getKey());
        self::assertSame([], $first->getMessages());
        self::assertSame([], $second->getMessages());

        SessionHistory::of($first)->addMessage(new UserMessage('First'));
        SessionHistory::of($second)->addMessage(new UserMessage('Second'));
        $keys = array_map(
            static fn(SessionSummary $session): string => $session->getKey(),
            $sessionStore->list(),
        );

        self::assertCount(2, array_unique($keys));

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $key);
        }
    }

    public function testAnEmptySessionIsKnownButNotListed(): void
    {
        $storage = new InMemoryStorage();
        $sessionStore = new SessionStore($storage, 'local-user');
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
        $storage = $files
            ? new FileStorage($this->directory)
            : new InMemoryStorage();
        $history = (new SessionStore($storage, 'local-user'))->create();
        SessionHistory::of($history)->addMessage(new UserMessage('Written earlier'));
        $history->setTitle('Written earlier');
        $reopened = new SessionStore($files
            ? new FileStorage($this->directory)
            : $storage, 'local-user');
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
        $sessionStore = new SessionStore(new InMemoryStorage(), 'local-user');
        $first = $sessionStore->create();
        SessionHistory::of($first)->addMessage(new UserMessage('The older subject'));
        $first->setTitle('The older subject');
        SessionHistory::of($first)->addMessage(new AssistantMessage('An answer'));
        $second = $sessionStore->create();
        SessionHistory::of($second)->addMessage(new UserMessage('The newer subject'));
        $second->setTitle('The newer subject');

        self::assertSame(
            ['The newer subject', 'The older subject'],
            array_map(
                static fn(SessionSummary $session): ?string => $session->getTitle(),
                $sessionStore->list(),
            ),
        );

        SessionHistory::of($first)->addMessage(new UserMessage('A later question'));
        $listed = $sessionStore->list();

        self::assertSame('The older subject', $listed[0]->getTitle());
        self::assertGreaterThan($listed[1]->getLastUsedAt(), $listed[0]->getLastUsedAt());
    }

    public function testUnknownKeysAreRejectedWithoutCreatingAHistory(): void
    {
        $storage = new InMemoryStorage();
        $sessionStore = new SessionStore($storage, 'local-user');

        self::assertNull($sessionStore->get('unknown'));

        self::assertNull($storage->get(Session::namespaceForUser('local-user'), 'unknown'));
        self::assertSame([], $storage->list(Session::namespaceForUser('local-user')));
    }

    public function testUserContentIsListedWithoutDerivingATitle(): void
    {
        $sessionStore = new SessionStore(new InMemoryStorage(), 'local-user');
        $history = $sessionStore->create();
        SessionHistory::of($history)->addMessage(new UserMessage(" \n\t"));
        SessionHistory::of($history)->addMessage(new AssistantMessage('An introductory answer'));
        SessionHistory::of($history)->addMessage(new UserMessage([
            new ReasoningContent('Internal reasoning'),
            new ImageContent('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', SourceType::BASE64, 'image/png'),
        ]));

        $withoutText = $sessionStore->list();
        self::assertCount(1, $withoutText);
        self::assertNull($withoutText[0]->getTitle());

        SessionHistory::of($history)->addMessage(new AssistantMessage('Image received'));
        $title = "  A\x00 title\nwith \x1b[31mcolor\x1b[0m "
            . str_repeat('long words ', 30);
        SessionHistory::of($history)->addMessage(new UserMessage([
            new ImageContent('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', SourceType::BASE64, 'image/png'),
            new TextContent($title),
        ]));
        SessionHistory::of($history)->addMessage(new AssistantMessage('An answer'));
        SessionHistory::of($history)->addMessage(new UserMessage('Another subject'));

        self::assertNull($sessionStore->list()[0]->getTitle());
    }

    public function testFileOnlySessionUsesItsFilenameAndCanBeResumed(): void
    {
        $storage = new FileStorage($this->directory);
        $store = new SessionStore($storage, 'local-user');
        $session = $store->create();
        SessionHistory::of($session)->addMessage(new UserMessage(new FileContent('https://example.com/report.pdf', SourceType::URL, 'application/pdf', 'report.pdf')));
        $reopened = new SessionStore(new FileStorage($this->directory), 'local-user');
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
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $sessionStore = new SessionStore($storage, 'local-user');
        SessionHistory::of($sessionStore->create())->addMessage(new UserMessage('First'));
        SessionHistory::of($sessionStore->create())->addMessage(new UserMessage('Second'));
        $keys = [];

        foreach ($storage->list(Session::namespaceForUser('local-user')) as $entry) {
            $keys[] = $entry['key'];
            $storage->update(Session::namespaceForUser('local-user'), $entry['key'], [
                ['path' => ['lastUsedAt'], 'value' => '2026-09-04T12:00:00.000000+00:00'],
            ]);
        }

        sort($keys);

        self::assertSame($keys, array_map(
            static fn(SessionSummary $session): string => $session->getKey(),
            $sessionStore->list(),
        ));
    }

    public function testFileSummaryRetainsIdentityTitleAndLastUse(): void
    {
        $sessionStore = new SessionStore(new FileStorage($this->directory), 'local-user');
        $history = $sessionStore->create();
        SessionHistory::of($history)->addMessage(new UserMessage('Stored in a file'));
        $history->setTitle('Stored in a file');
        $reopened = new SessionStore(new FileStorage($this->directory), 'local-user');
        $listed = $reopened->list();

        self::assertCount(1, $listed);
        self::assertSame($history->getKey(), $listed[0]->getKey());
        self::assertSame('Stored in a file', $listed[0]->getTitle());
    }

    public function testLegacyFilesAreNeitherDiscoveredNorMigrated(): void
    {
        mkdir($this->directory, recursive: true);
        $legacy = $this->directory . '/neuron_legacy-key.chat';
        file_put_contents($legacy, '[{"content":"Old"}]');
        $contents = file_get_contents($legacy);
        $sessionStore = new SessionStore(new FileStorage($this->directory), 'local-user');

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
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $alice = new SessionStore($storage, 'alice@example.com');
        $bob = new SessionStore($storage, 'bob / local');
        $session = $alice->create();
        $key = $session->getKey();

        self::assertSame('alice@example.com', $session->getUserId());
        self::assertNotNull($alice->get($key));
        self::assertNull($bob->get($key));
        SessionHistory::of($session)->addMessage(new UserMessage('Alice conversation'));
        self::assertSame([], $bob->list());
        self::assertCount(1, $alice->list());
        $bob->delete($key);
        self::assertNotNull($alice->get($key));

        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, 'alice@example.com');
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
        $storage = new InMemoryStorage();
        $key = $storage->create('sessions', []);
        $sessionStore = new SessionStore($storage, 'local-user');
        self::assertNull($sessionStore->get($key));
        self::assertSame([], $sessionStore->list());
        $sessionStore->delete($key);
        self::assertNotNull($storage->get('sessions', $key));
    }

    public function testTitlesPersistIndependentlyFromMessagesAndLastUseTime(): void
    {
        $storage = new InMemoryStorage();
        $store = new SessionStore($storage, 'local-user');
        $session = $store->create();
        self::assertNull($session->getTitle());
        SessionHistory::of($session)->addMessage(new UserMessage('ciao'));
        $before = $session->getMessages();
        $lastUsed = $store->list()[0]->getLastUsedAt();
        $reopened = $store->get($session->getKey());
        self::assertNotNull($reopened);

        $reopened->setTitle('  Configurazione Redis  ');

        self::assertSame('Configurazione Redis', $session->getTitle());
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
