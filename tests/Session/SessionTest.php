<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use function array_map;
use function bin2hex;
use function glob;
use function is_dir;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;

final class SessionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-session-history-' . bin2hex(random_bytes(6));
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

    public function testItRepresentsAnOwnedEmptyConversation(): void
    {
        $session = (new SessionStore(new InMemoryStorage(), 'local-user'))->create();

        self::assertSame([], $session->getMessages());
        self::assertNotSame('', $session->getKey());
        self::assertSame('local-user', $session->getUserId());
    }

    public function testReopeningDefersMessageValidationUntilMessagesAreRead(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('sessions', 'malformed', [42], ['userId' => 'local-user']);
        $session = (new SessionStore($storage, 'local-user'))->get('malformed');

        self::assertNotNull($session);
        self::assertSame('malformed', $session->getKey());
        self::assertSame('local-user', $session->getUserId());
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Every stored Chat History entry must be a JSON object.');
        $session->getMessages();
    }

    #[DataProvider('storageKinds')]
    public function testMessagesRoundTripWithTheirSupportedContent(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $history = (new SessionStore($storage, 'local-user'))->create();
        $question = new UserMessage([
            (new TextContent('What is shown?'))->setMetadata(['part' => 1]),
            new ImageContent(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                    . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                SourceType::BASE64,
                'image/png',
            ),
        ]);
        $answer = (new AssistantMessage([
            new ReasoningContent('I inspected it.', 'reasoning-1'),
            new TextContent('A small diagram.'),
        ]))->setUsage(new Usage(18, 4));
        $tool = ToolCall::make(name: 'inspect', description: 'Inspect an image')
            ->setInputs(['detail' => 'high'])
            ->setCallId('call-1');
        $result = ToolCall::make(name: 'inspect', description: 'Inspect an image')
            ->setInputs(['detail' => 'high'])
            ->setCallId('call-1')
            ->setResult('diagram');

        SessionHistory::of($history)->addMessage($question);
        SessionHistory::of($history)->addMessage($answer);
        SessionHistory::of($history)->addMessage(new ToolCallMessage(tools: [$tool]));
        SessionHistory::of($history)->addMessage(new ToolResultMessage([$result]));

        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, 'local-user'))->get($history->getKey());

        self::assertNotNull($reopened);
        $messages = $reopened->getMessages();

        self::assertCount(4, $messages);
        self::assertEquals(
            $question->getContentBlocks(),
            $messages[0]->getContentBlocks(),
        );
        self::assertEquals(
            $answer->getContentBlocks(),
            $messages[1]->getContentBlocks(),
        );
        self::assertEquals(new Usage(18, 4), $messages[1]->getUsage());
        self::assertInstanceOf(ToolCallMessage::class, $messages[2]);
        self::assertSame('inspect', $messages[2]->getToolCalls()[0]->getName());
        self::assertSame('call-1', $messages[2]->getToolCalls()[0]->getCallId());
        self::assertInstanceOf(ToolResultMessage::class, $messages[3]);
        self::assertSame('diagram', $messages[3]->getToolCalls()[0]->getResult());
    }

    public function testSavingReplacesOnlyTheSelectedStorageValue(): void
    {
        $storage = new InMemoryStorage();
        $sessionStore = new SessionStore($storage, 'local-user');
        $other = $sessionStore->create();
        SessionHistory::of($other)->addMessage(new UserMessage('Untouched'));
        $history = $sessionStore->create();
        SessionHistory::of($history)->addMessage(new UserMessage('First'));
        $first = $sessionStore->get($history->getKey());
        self::assertNotNull($first);
        SessionHistory::of($history)->addMessage(new AssistantMessage('Second'));
        self::assertCount(2, $first->getMessages());
        $current = $sessionStore->get($history->getKey());
        self::assertNotNull($current);
        self::assertCount(2, $current->getMessages());
        $untouched = $sessionStore->get($other->getKey());
        self::assertNotNull($untouched);
        self::assertSame('Untouched', $untouched->getMessages()[0]->getContent());
    }

    #[DataProvider('storageKinds')]
    public function testTrimmingPersistsTheHistoryNeuronAiKeeps(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $history = (new SessionStore($storage, 'local-user'))->create();
        SessionHistory::of($history)->addMessage(new UserMessage('Discarded'));
        SessionHistory::of($history)->addMessage((new AssistantMessage('Discarded answer'))->setUsage(new Usage(48000, 1000)));
        $question = str_repeat('Next question ', 2000);
        SessionHistory::of($history)->addMessage(new UserMessage($question));

        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, 'local-user'))->get($history->getKey());
        self::assertNotNull($reopened);
        self::assertCount(3, $reopened->getMessages());
        self::assertSame(
            [$question],
            array_map(
                static fn(Message $message): ?string => $message->getContent(),
                SessionHistory::of($reopened)->getMessages(),
            ),
        );
    }

    #[DataProvider('storageKinds')]
    public function testClearingPersistsAnEmptyHistory(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $history = (new SessionStore($storage, 'local-user'))->create();
        SessionHistory::of($history)->addMessage(new UserMessage('Remove me'));

        SessionHistory::of($history)->flushAll();

        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, 'local-user'))->get($history->getKey());
        self::assertNotNull($reopened);
        self::assertSame(
            [],
            $reopened->getMessages(),
        );
    }
}
