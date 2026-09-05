<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use InvalidArgumentException;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\Sessions;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionsTest extends TestCase
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

    #[DataProvider('storageKinds')]
    public function testRetainingHistoryPreservesStructuredMessagesAndLaterAdditions(bool $files): void
    {
        $sessions = new Sessions($files ? new FileStorage($this->directory) : new InMemoryStorage());
        $original = new InMemoryChatHistory();
        $original->addMessage(new UserMessage([
            new TextContent('Initial subject'),
            new ImageContent('https://example.com/image.png', SourceType::URL),
        ]));
        $original->addMessage(new AssistantMessage([
            new ReasoningContent('Earlier reasoning'),
            new TextContent('Earlier answer'),
        ]));
        $retained = $sessions->retain($original);

        self::assertSame($original->getMessages(), $retained->getMessages());
        $retained->addMessage(new UserMessage('Later question'));
        $retained->addMessage(new AssistantMessage('Later answer'));
        $listed = $sessions->list();

        self::assertCount(1, $listed);
        self::assertSame('Initial subject', $listed[0]->title);
        self::assertEquals($retained->getMessages(), $sessions->resume($listed[0]->key)->getMessages());
    }

    public function testRetainingAnOversizedHistoryDoesNotTrimTheImport(): void
    {
        $original = new InMemoryChatHistory(contextWindow: 1000000);
        $original->addMessage(new UserMessage('Keep the beginning'));
        $original->addMessage(new AssistantMessage(str_repeat('large message ', 60000)));
        $sessions = new Sessions(new InMemoryStorage());

        $retained = $sessions->retain($original);

        self::assertCount(2, $retained->getMessages());
        self::assertSame($original->getMessages(), $retained->getMessages());
        self::assertEquals($original->getMessages(), $sessions->resume($sessions->list()[0]->key)->getMessages());
    }

    public function testRetainingAPreselectedSessionKeepsItsKeyWithoutDuplicatingIt(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new Sessions($storage);
        $sessions->start()->addMessage(new UserMessage('Selected subject'));
        $key = $sessions->list()[0]->key;
        $selected = $sessions->resume($key);

        $retained = (new Sessions($storage))->retain($selected);
        $retained->addMessage(new AssistantMessage('Continued'));

        self::assertSame($selected, $retained);
        self::assertCount(1, $sessions->list());
        self::assertSame($key, $sessions->list()[0]->key);
        self::assertCount(2, $sessions->resume($key)->getMessages());
    }

    public function testRetainingAHistoryFromAnotherStorageMakesItResumableLocally(): void
    {
        $foreign = new Sessions(new InMemoryStorage());
        $history = $foreign->start();
        $history->addMessage(new UserMessage('Imported subject'));
        $local = new Sessions(new InMemoryStorage());

        $retained = $local->retain($history);
        $retained->addMessage(new AssistantMessage('Local continuation'));

        self::assertCount(2, $local->resume($local->list()[0]->key)->getMessages());
        self::assertCount(1, $foreign->resume($foreign->list()[0]->key)->getMessages());
    }

    public function testStartMintsDistinctStorageSafeKeysAndEmptyHistories(): void
    {
        $sessions = new Sessions(new InMemoryStorage());
        $first = $sessions->start();
        $second = $sessions->start();

        self::assertSame([], $first->getMessages());
        self::assertSame([], $second->getMessages());

        $first->addMessage(new UserMessage('First'));
        $second->addMessage(new UserMessage('Second'));
        $keys = array_map(
            static fn (Session $session): string => $session->key,
            $sessions->list(),
        );

        self::assertCount(2, array_unique($keys));

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $key);
        }
    }

    public function testAnEmptySessionIsKnownButNotListed(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new Sessions($storage);
        $sessions->start();
        $key = iterator_to_array($storage->entries('sessions'))[0]->key;

        self::assertSame([], $sessions->list());
        self::assertSame([], $sessions->resume($key)->getMessages());
    }

    #[DataProvider('storageKinds')]
    public function testSessionsCanBeListedAndResumedByANewInstance(bool $files): void
    {
        $storage = $files
            ? new FileStorage($this->directory)
            : new InMemoryStorage();
        $history = (new Sessions($storage))->start();
        $history->addMessage(new UserMessage('Written earlier'));
        $reopened = new Sessions($files
            ? new FileStorage($this->directory)
            : $storage);
        $listed = $reopened->list();

        self::assertCount(1, $listed);
        self::assertSame('Written earlier', $listed[0]->title);
        self::assertSame(
            'Written earlier',
            $reopened->resume($listed[0]->key)->getMessages()[0]->getContent(),
        );
    }

    /** @return array<string, array{bool}> */
    public static function storageKinds(): array
    {
        return ['memory' => [false], 'files' => [true]];
    }

    public function testListUsesTheOpeningWordsAndMostRecentUseOrder(): void
    {
        $sessions = new Sessions(new InMemoryStorage());
        $first = $sessions->start();
        $first->addMessage(new UserMessage('The older subject'));
        $first->addMessage(new AssistantMessage('An answer'));
        $second = $sessions->start();
        $second->addMessage(new UserMessage('The newer subject'));

        self::assertSame(
            ['The newer subject', 'The older subject'],
            array_map(
                static fn (Session $session): string => $session->title,
                $sessions->list(),
            ),
        );

        $first->addMessage(new UserMessage('A later question'));
        $listed = $sessions->list();

        self::assertSame('The older subject', $listed[0]->title);
        self::assertGreaterThan($listed[1]->lastUsedAt, $listed[0]->lastUsedAt);
        self::assertGreaterThan(0, $listed[0]->size);
    }

    public function testUnknownKeysAreRejectedWithoutCreatingAHistory(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new Sessions($storage);

        try {
            $sessions->resume('unknown');
            self::fail('An unknown Session was resumed.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'No Session is named by that key.',
                $exception->getMessage(),
            );
        }

        self::assertNull($storage->read('sessions', 'unknown'));
        self::assertSame([], iterator_to_array($storage->entries('sessions')));
    }

    public function testTitleUsesTheFirstNonBlankUserAuthoredTextUnchanged(): void
    {
        $sessions = new Sessions(new InMemoryStorage());
        $history = $sessions->start();
        $history->addMessage(new UserMessage(" \n\t"));
        $history->addMessage(new AssistantMessage('An introductory answer'));
        $history->addMessage(new UserMessage([
            new ReasoningContent('Internal reasoning'),
            new ImageContent('https://example.com/image.png', SourceType::URL),
        ]));

        $withoutText = $sessions->list();
        self::assertSame([], $withoutText);

        $history->addMessage(new AssistantMessage('Image received'));
        $title = "  A\x00 title\nwith \x1b[31mcolor\x1b[0m "
            . str_repeat('long words ', 30);
        $history->addMessage(new UserMessage([
            new ImageContent('https://example.com/image.png', SourceType::URL),
            new TextContent($title),
        ]));
        $history->addMessage(new AssistantMessage('An answer'));
        $history->addMessage(new UserMessage('Another subject'));

        self::assertSame($title, $sessions->list()[0]->title);
    }

    public function testEqualLastUseTimesAreOrderedByKey(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new Sessions($storage);
        $sessions->start()->addMessage(new UserMessage('First'));
        $sessions->start()->addMessage(new UserMessage('Second'));
        $keys = [];

        foreach ($storage->entries('sessions') as $document) {
            $keys[] = $document->key;
            $storage->write('sessions', $document->key, $document->data, [
                'lastUsedAt' => '2026-09-04T12:00:00.000000+00:00',
            ]);
        }

        sort($keys);

        self::assertSame($keys, array_map(
            static fn (Session $session): string => $session->key,
            $sessions->list(),
        ));
    }

    public function testFilePayloadIsKeyNamedJsonAndReportsItsDataSize(): void
    {
        $sessions = new Sessions(new FileStorage($this->directory));
        $history = $sessions->start();
        $history->addMessage(new UserMessage('Stored in a file'));
        $listed = $sessions->list();

        self::assertCount(1, $listed);
        $path = $this->directory . '/sessions/' . $listed[0]->key . '.json';
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertJson($contents);
        $document = (new FileStorage($this->directory))->read(
            'sessions',
            $listed[0]->key,
        );
        self::assertNotNull($document);
        self::assertSame($document->size(), $listed[0]->size);
    }

    public function testLegacyFilesAreNeitherDiscoveredNorMigrated(): void
    {
        mkdir($this->directory, recursive: true);
        $legacy = $this->directory . '/neuron_legacy-key.chat';
        file_put_contents($legacy, '[{"content":"Old"}]');
        $contents = file_get_contents($legacy);
        $sessions = new Sessions(new FileStorage($this->directory));

        self::assertSame([], $sessions->list());

        try {
            $sessions->resume('legacy-key');
            self::fail('A legacy Session was resumed.');
        } catch (InvalidArgumentException) {
        }

        self::assertFileExists($legacy);
        self::assertSame($contents, file_get_contents($legacy));
        self::assertFileDoesNotExist(
            $this->directory . '/sessions/legacy-key.json',
        );
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
