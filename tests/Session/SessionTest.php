<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

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

    public function testItExtendsNeuronAiHistory(): void
    {
        $session = (new SessionStore(new InMemoryStorage(), 'local-user'))->create();

        self::assertInstanceOf(AbstractChatHistory::class, $session);
        self::assertSame('local-user', $session->getUserId());
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
        $tool = Tool::make('inspect', 'Inspect an image')
            ->setParameters(['type' => 'object'])
            ->setInputs(['detail' => 'high'])
            ->setCallId('call-1');
        $result = Tool::make('inspect', 'Inspect an image')
            ->setInputs(['detail' => 'high'])
            ->setCallId('call-1')
            ->setResult('diagram');

        $history->addMessage($question);
        $history->addMessage($answer);
        $history->addMessage(new ToolCallMessage(tools: [$tool]));
        $history->addMessage(new ToolResultMessage([$result]));

        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, 'local-user'))->read($history->getKey());

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
        self::assertSame('inspect', $messages[2]->getTools()[0]->getName());
        self::assertSame('call-1', $messages[2]->getTools()[0]->getCallId());
        self::assertInstanceOf(ToolResultMessage::class, $messages[3]);
        self::assertSame('diagram', $messages[3]->getTools()[0]->getResult());
    }

    public function testSavingReplacesOnlyTheSelectedStorageValue(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'local-user');
        $other = $sessions->create();
        $other->addMessage(new UserMessage('Untouched'));
        $history = $sessions->create();
        $history->addMessage(new UserMessage('First'));
        $first = $sessions->read($history->getKey());
        self::assertNotNull($first);
        $history->addMessage(new AssistantMessage('Second'));
        self::assertCount(1, $first->getMessages());
        $current = $sessions->read($history->getKey());
        self::assertNotNull($current);
        self::assertCount(2, $current->getMessages());
        $untouched = $sessions->read($other->getKey());
        self::assertNotNull($untouched);
        self::assertSame('Untouched', $untouched->getMessages()[0]->getContent());
    }

    #[DataProvider('storageKinds')]
    public function testTrimmingPersistsTheHistoryNeuronAiKeeps(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $history = (new SessionStore($storage, 'local-user'))->create();
        $history->addMessage(new UserMessage('Discarded'));
        $history->addMessage((new AssistantMessage('Discarded answer'))->setUsage(new Usage(48000, 1000)));
        $question = str_repeat('Next question ', 2000);
        $history->addMessage(new UserMessage($question));

        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, 'local-user'))->read($history->getKey());
        self::assertNotNull($reopened);
        self::assertSame(
            [$question],
            array_map(
                static fn (Message $message): ?string => $message->getContent(),
                $reopened->getMessages(),
            ),
        );
    }

    #[DataProvider('storageKinds')]
    public function testClearingPersistsAnEmptyHistory(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $history = (new SessionStore($storage, 'local-user'))->create();
        $history->addMessage(new UserMessage('Remove me'));

        $history->flushAll();

        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, 'local-user'))->read($history->getKey());
        self::assertNotNull($reopened);
        self::assertSame(
            [],
            $reopened->getMessages(),
        );
    }
}
