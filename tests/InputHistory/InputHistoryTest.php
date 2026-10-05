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
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use function array_map;
use function bin2hex;
use function is_dir;
use function is_link;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

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

        self::assertSame([], $first->list());
        self::assertSame([], $second->list());

        $first->append(new UserMessage('  Message exactly as submitted  '));
        self::assertSame(['  Message exactly as submitted  '], array_map(static fn(UserMessage $message): ?string => $message->getContent(), $second->list()));

        $second->append(new UserMessage('/resume session-key'));
        $first->append(new UserMessage("Another\nmessage"));
        $expected = [
            '  Message exactly as submitted  ',
            '/resume session-key',
            "Another\nmessage",
        ];

        self::assertSame($expected, array_map(static fn(UserMessage $message): ?string => $message->getContent(), $first->list()));
        self::assertSame($expected, array_map(static fn(UserMessage $message): ?string => $message->getContent(), $second->list()));
        self::assertSame($expected, array_map(static fn(UserMessage $message): ?string => $message->getContent(), (new InputHistory($files
            ? new FileStorage($this->directory)
            : $storage))->list()));
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

        $first->append(new UserMessage(" \n\t "));
        self::assertSame([], $second->list());

        $first->append(new UserMessage('same'));
        $second->append(new UserMessage('same'));
        $first->append(new UserMessage(''));
        $second->append(new UserMessage('different'));
        $first->append(new UserMessage('same'));
        $second->append(new UserMessage(' same '));

        self::assertSame(
            ['same', 'different', 'same', ' same '],
            array_map(static fn(UserMessage $message): ?string => $message->getContent(), $first->list()),
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

        $inputs->append(new UserMessage('/summarize'));
        SessionHistory::of($history)->addMessage(new UserMessage('A generated prompt for the Agent'));
        $key = $sessionStore->list()[0]->getKey();
        SessionHistory::of($sessionStore->create())->addMessage(new UserMessage('Another conversation'));
        $inputs->append(new UserMessage('A submitted message'));
        $sessionStore->get($key);

        self::assertSame(
            ['/summarize', 'A submitted message'],
            array_map(static fn(UserMessage $message): ?string => $message->getContent(), (new InputHistory($storage))->list()),
        );
        self::assertCount(2, $sessionStore->list());
    }

    #[DataProvider('storageKinds')]
    public function testAttachmentsAndMetadataSurviveStorageRecall(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $inputs = new InputHistory($storage);
        $inputs->append(new UserMessage('/resume original-key'));
        $message = new UserMessage([
            new ImageContent('https://example.com/photo.png', SourceType::URL, 'image/png'),
            new FileContent('https://example.com/report.pdf', SourceType::URL, 'application/pdf', 'report.pdf'),
        ]);
        $message->addMetadata('original', 'submission');
        $snapshot = json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $inputs->append($message);
        $inputs->append($message);
        $message->setContents('Changed after submission');

        $reopened = new InputHistory($files ? new FileStorage($this->directory) : $storage);
        self::assertCount(2, $reopened->list());
        $recalled = $reopened->list()[1];
        self::assertSame($snapshot, json_decode(json_encode($recalled, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        self::assertNull($recalled->getContent());
        self::assertSame('/resume original-key', $reopened->list()[0]->getContent());
    }

    public function testStoredStringsAreRejected(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('input-history', 'entries', ['old text entry']);

        $this->expectException(UnexpectedValueException::class);
        (new InputHistory($storage))->list();
    }

    public function testStoredAssistantMessagesAreRejectedAsInput(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('input-history', 'entries', [['role' => 'assistant', 'content' => 'invalid']]);

        $this->expectException(UnexpectedValueException::class);
        (new InputHistory($storage))->list();
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
