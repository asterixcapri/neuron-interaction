<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Session\SessionSummary;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionStoreTest extends TestCase
{
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
        $sessions = new SessionStore(new InMemoryStorage(), 'local-user');
        $first = $sessions->create();
        $second = $sessions->create();

        self::assertNotSame($first->getKey(), $second->getKey());
        self::assertSame([], $first->getMessages());
        self::assertSame([], $second->getMessages());

        $first->addMessage(new UserMessage('First'));
        $second->addMessage(new UserMessage('Second'));
        $keys = array_map(
            static fn (SessionSummary $session): string => $session->key,
            $sessions->summaries(),
        );

        self::assertCount(2, array_unique($keys));

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $key);
        }
    }

    public function testAnEmptySessionIsKnownButNotListed(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'local-user');
        $key = $sessions->create()->getKey();

        self::assertSame([], $sessions->summaries());
        $resumed = $sessions->read($key);
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
        $history->addMessage(new UserMessage('Written earlier'));
        $reopened = new SessionStore($files
            ? new FileStorage($this->directory)
            : $storage, 'local-user');
        $listed = $reopened->summaries();

        self::assertCount(1, $listed);
        self::assertSame('Written earlier', $listed[0]->title);
        $session = $reopened->read($listed[0]->key);
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
        $sessions = new SessionStore(new InMemoryStorage(), 'local-user');
        $first = $sessions->create();
        $first->addMessage(new UserMessage('The older subject'));
        $first->addMessage(new AssistantMessage('An answer'));
        $second = $sessions->create();
        $second->addMessage(new UserMessage('The newer subject'));

        self::assertSame(
            ['The newer subject', 'The older subject'],
            array_map(
                static fn (SessionSummary $session): string => $session->title,
                $sessions->summaries(),
            ),
        );

        $first->addMessage(new UserMessage('A later question'));
        $listed = $sessions->summaries();

        self::assertSame('The older subject', $listed[0]->title);
        self::assertGreaterThan($listed[1]->lastUsedAt, $listed[0]->lastUsedAt);
        self::assertGreaterThan(0, $listed[0]->size);
    }

    public function testUnknownKeysAreRejectedWithoutCreatingAHistory(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'local-user');

        self::assertNull($sessions->read('unknown'));

        self::assertNull($storage->read('sessions', 'unknown'));
        self::assertSame([], iterator_to_array($storage->entries('sessions')));
    }

    public function testTitleUsesTheFirstNonBlankUserAuthoredTextUnchanged(): void
    {
        $sessions = new SessionStore(new InMemoryStorage(), 'local-user');
        $history = $sessions->create();
        $history->addMessage(new UserMessage(" \n\t"));
        $history->addMessage(new AssistantMessage('An introductory answer'));
        $history->addMessage(new UserMessage([
            new ReasoningContent('Internal reasoning'),
            new ImageContent('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', SourceType::BASE64, 'image/png'),
        ]));

        $withoutText = $sessions->summaries();
        self::assertSame([], $withoutText);

        $history->addMessage(new AssistantMessage('Image received'));
        $title = "  A\x00 title\nwith \x1b[31mcolor\x1b[0m "
            . str_repeat('long words ', 30);
        $history->addMessage(new UserMessage([
            new ImageContent('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', SourceType::BASE64, 'image/png'),
            new TextContent($title),
        ]));
        $history->addMessage(new AssistantMessage('An answer'));
        $history->addMessage(new UserMessage('Another subject'));

        self::assertSame($title, $sessions->summaries()[0]->title);
    }

    public function testEqualLastUseTimesAreOrderedByKey(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'local-user');
        $sessions->create()->addMessage(new UserMessage('First'));
        $sessions->create()->addMessage(new UserMessage('Second'));
        $keys = [];

        foreach ($storage->entries('sessions') as $document) {
            $keys[] = $document->key;
            $storage->write('sessions', $document->key, $document->data, [
                'userId' => 'local-user',
                'lastUsedAt' => '2026-09-04T12:00:00.000000+00:00',
            ]);
        }

        sort($keys);

        self::assertSame($keys, array_map(
            static fn (SessionSummary $session): string => $session->key,
            $sessions->summaries(),
        ));
    }

    public function testFileSummaryReportsThePersistedConversationSize(): void
    {
        $sessions = new SessionStore(new FileStorage($this->directory), 'local-user');
        $history = $sessions->create();
        $history->addMessage(new UserMessage('Stored in a file'));
        $reopened = new SessionStore(new FileStorage($this->directory), 'local-user');
        $listed = $reopened->summaries();

        self::assertCount(1, $listed);
        self::assertSame($history->getKey(), $listed[0]->key);
        self::assertSame('Stored in a file', $listed[0]->title);
        self::assertGreaterThan(0, $listed[0]->size);
    }

    public function testLegacyFilesAreNeitherDiscoveredNorMigrated(): void
    {
        mkdir($this->directory, recursive: true);
        $legacy = $this->directory . '/neuron_legacy-key.chat';
        file_put_contents($legacy, '[{"content":"Old"}]');
        $contents = file_get_contents($legacy);
        $sessions = new SessionStore(new FileStorage($this->directory), 'local-user');

        self::assertSame([], $sessions->summaries());

        self::assertNull($sessions->read('legacy-key'));

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
        self::assertNotNull($alice->read($key));
        self::assertNull($bob->read($key));
        $session->addMessage(new UserMessage('Alice conversation'));
        self::assertSame([], $bob->summaries());
        self::assertCount(1, $alice->summaries());
        $bob->delete($key);
        self::assertNotNull($alice->read($key));

        $fresh = new SessionStore($files ? new FileStorage($this->directory) : $storage, 'alice@example.com');
        $reopened = $fresh->read($key);
        self::assertNotNull($reopened);
        self::assertSame('alice@example.com', $reopened->getUserId());
        self::assertSame('Alice conversation', $reopened->getMessages()[0]->getContent());
        $fresh->delete($key);
        $fresh->delete($key);
        self::assertNull($alice->read($key));
        self::assertSame([], $alice->summaries());
    }

    public function testOwnerlessDocumentsAreNotAssignedToTheStoreUser(): void
    {
        $storage = new InMemoryStorage();
        $document = $storage->create('sessions', []);
        $sessions = new SessionStore($storage, 'local-user');
        self::assertNull($sessions->read($document->key));
        self::assertSame([], $sessions->summaries());
        $sessions->delete($document->key);
        self::assertNotNull($storage->read('sessions', $document->key));
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
