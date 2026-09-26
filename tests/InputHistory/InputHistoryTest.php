<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\InputHistory;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class InputHistoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/neuron-interaction-input-history-'
            . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    #[DataProvider('storageKinds')]
    public function testExistingAdaptersSeeEachOthersSubmissionsWithoutLosingEntries(bool $files): void
    {
        $storage = $files
            ? new FileStorage($this->directory)
            : new InMemoryStorage();
        $first = new InputHistory($storage);
        $second = new InputHistory($files
            ? new FileStorage($this->directory)
            : $storage);

        self::assertSame([], $first->entries());
        self::assertSame([], $second->entries());

        $first->record(new UserMessage('  Message exactly as submitted  '));
        self::assertSame(['  Message exactly as submitted  '], array_map(static fn (UserMessage $message): ?string => $message->getContent(), $second->entries()));

        $second->record(new UserMessage('/resume session-key'));
        $first->record(new UserMessage("Another\nmessage"));
        $expected = [
            '  Message exactly as submitted  ',
            '/resume session-key',
            "Another\nmessage",
        ];

        self::assertSame($expected, array_map(static fn (UserMessage $message): ?string => $message->getContent(), $first->entries()));
        self::assertSame($expected, array_map(static fn (UserMessage $message): ?string => $message->getContent(), $second->entries()));
        self::assertSame($expected, array_map(static fn (UserMessage $message): ?string => $message->getContent(), (new InputHistory($files
            ? new FileStorage($this->directory)
            : $storage))->entries()));
    }

    #[DataProvider('storageKinds')]
    public function testBlankInputsAndOnlyConsecutiveExactDuplicatesAreIgnoredAcrossAdapters(bool $files): void
    {
        $storage = $files
            ? new FileStorage($this->directory)
            : new InMemoryStorage();
        $first = new InputHistory($storage);
        $second = new InputHistory($files
            ? new FileStorage($this->directory)
            : $storage);

        $first->record(new UserMessage(" \n\t "));
        self::assertSame([], $second->entries());

        $first->record(new UserMessage('same'));
        $second->record(new UserMessage('same'));
        $first->record(new UserMessage(''));
        $second->record(new UserMessage('different'));
        $first->record(new UserMessage('same'));
        $second->record(new UserMessage(' same '));

        self::assertSame(
            ['same', 'different', 'same', ' same '],
            array_map(static fn (UserMessage $message): ?string => $message->getContent(), $first->entries()),
        );
    }

    #[DataProvider('storageKinds')]
    public function testSessionsDoNotReplaceInputHistoryOrRecordGeneratedPrompts(bool $files): void
    {
        $storage = $files
            ? new FileStorage($this->directory)
            : new InMemoryStorage();
        $inputs = new InputHistory($storage);
        $sessionStore = new SessionStore($storage, 'local-user');
        $history = $sessionStore->create();

        $inputs->record(new UserMessage('/summarize'));
        $history->addMessage(new UserMessage('A generated prompt for the Agent'));
        $key = $sessionStore->summaries()[0]->key;
        $sessionStore->create()->addMessage(new UserMessage('Another conversation'));
        $inputs->record(new UserMessage('A submitted message'));
        $sessionStore->read($key);

        self::assertSame(
            ['/summarize', 'A submitted message'],
            array_map(static fn (UserMessage $message): ?string => $message->getContent(), (new InputHistory($storage))->entries()),
        );
        self::assertCount(2, $sessionStore->summaries());
    }

    #[DataProvider('storageKinds')]
    public function testAttachmentsAndMetadataSurviveStorageRecallAndDraftRestoration(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $inputs = new InputHistory($storage);
        $inputs->record(new UserMessage('/resume original-key'));
        $message = new UserMessage([
            new ImageContent('https://example.com/photo.png', SourceType::URL, 'image/png'),
            new FileContent('https://example.com/report.pdf', SourceType::URL, 'application/pdf', 'report.pdf'),
        ]);
        $message->addMetadata('original', 'submission');
        $snapshot = json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $inputs->record($message);
        $inputs->record($message);
        $message->setContents('Changed after submission');

        $reopened = new InputHistory($files ? new FileStorage($this->directory) : $storage);
        self::assertCount(2, $reopened->entries());
        $draft = new UserMessage(new ImageContent('https://example.com/draft.png', SourceType::URL));
        $recalled = $reopened->older($draft);
        self::assertInstanceOf(UserMessage::class, $recalled);
        self::assertSame($snapshot, json_decode(json_encode($recalled, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        self::assertNull($recalled->getContent());
        self::assertSame('/resume original-key', $reopened->older()?->getContent());
        self::assertInstanceOf(UserMessage::class, $reopened->newer());
        self::assertSame($draft, $reopened->newer());
    }

    public function testStoredStringsAreRejected(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('input-history', 'entries', ['old text entry']);

        $this->expectException(UnexpectedValueException::class);
        (new InputHistory($storage))->entries();
    }

    public function testStoredAssistantMessagesAreRejectedAsInput(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('input-history', 'entries', [['role' => 'assistant', 'content' => 'invalid']]);

        $this->expectException(UnexpectedValueException::class);
        (new InputHistory($storage))->entries();
    }

    /** @return array<string, array{bool}> */
    public static function storageKinds(): array
    {
        return ['memory' => [false], 'files' => [true]];
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
            is_dir($path) && !is_link($path)
                ? $this->removeDirectory($path)
                : unlink($path);
        }

        rmdir($directory);
    }
}
