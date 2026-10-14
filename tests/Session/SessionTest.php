<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\MissingEntryException;
use NeuronInteraction\Tests\History\SessionMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

use function array_map;
use function bin2hex;
use function is_dir;
use function is_link;
use function iterator_to_array;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use const PHP_INT_MAX;

final class SessionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-session-history-' . bin2hex(random_bytes(6));
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

    public function testItRepresentsAnOwnedEmptyConversation(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local-user'))->create();

        self::assertSame([], $session->getMessages());
        self::assertNotSame('', $session->getKey());
        self::assertSame('local-user', $session->getUserId());
    }

    public function testReopeningDefersMessageValidationUntilMessagesAreRead(): void
    {
        $nativeMessages = $this->createMock(MessageStoreInterface::class);
        $nativeMessages->expects(self::once())->method('loadAll')->willThrowException(new UnexpectedValueException('Corrupt messages'));
        $storage = new InMemoryStorage();
        $store = new SessionStore($storage, $nativeMessages, 'local-user');
        $created = $store->create();
        $session = $store->get($created->getKey());

        self::assertNotNull($session);
        self::assertSame($created->getKey(), $session->getKey());
        self::assertSame('local-user', $session->getUserId());
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Corrupt messages');
        $session->getMessages();
    }

    #[DataProvider('storageKinds')]
    public function testMessagesRoundTripWithTheirSupportedContent(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $history = (new SessionStore($storage, $nativeMessages, 'local-user'))->create();
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

        $messageStore = new SessionMessages($storage, $nativeMessages, 'local-user');
        $messageStore->append($history->getKey(), $question);
        $messageStore->append($history->getKey(), $answer);
        $messageStore->append($history->getKey(), new ToolCallMessage(tools: [$tool]));
        $messageStore->append($history->getKey(), new ToolResultMessage([$result]));

        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, $nativeMessages, 'local-user'))->get($history->getKey());

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
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $sessionStore = new SessionStore($storage, $nativeMessages, 'local-user');
        $other = $sessionStore->create();
        $messageStore = new SessionMessages($storage, $nativeMessages, 'local-user');
        $messageStore->append($other->getKey(), new UserMessage('Untouched'));
        $history = $sessionStore->create();
        $messageStore->append($history->getKey(), new UserMessage('First'));
        $first = $sessionStore->get($history->getKey());
        self::assertNotNull($first);
        $messageStore->append($history->getKey(), new AssistantMessage('Second'));
        self::assertCount(2, $first->getMessages());
        $current = $sessionStore->get($history->getKey());
        self::assertNotNull($current);
        self::assertCount(2, $current->getMessages());
        $untouched = $sessionStore->get($other->getKey());
        self::assertNotNull($untouched);
        self::assertSame('Untouched', $untouched->getMessages()[0]->getContent());
    }

    #[DataProvider('storageKinds')]
    public function testArchivedMessagesRemainAvailableWhenReopeningTheSession(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $history = (new SessionStore($storage, $nativeMessages, 'local-user'))->create();
        $messages = new SessionMessages($storage, $nativeMessages, 'local-user');
        $messages->append($history->getKey(), new UserMessage('Earlier question'));
        $messages->append($history->getKey(), new AssistantMessage('Earlier answer'));
        $question = 'Next question';
        $messages->append($history->getKey(), new UserMessage($question));
        $messages->archive($history->getKey(), 2);

        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, $nativeMessages, 'local-user'))->get($history->getKey());
        self::assertNotNull($reopened);
        self::assertCount(3, $reopened->getMessages());
        self::assertSame(
            [$question],
            array_map(
                static fn(Message $message): ?string => $message->getContent(),
                (new SessionMessages($files ? new FileStorage($this->directory) : $storage, $nativeMessages, 'local-user'))->loadActive($reopened->getKey()),
            ),
        );
    }

    #[DataProvider('storageKinds')]
    public function testClearingPersistsAnEmptyHistory(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $history = (new SessionStore($storage, $nativeMessages, 'local-user'))->create();
        $messageStore = new SessionMessages($storage, $nativeMessages, 'local-user');
        $messageStore->append($history->getKey(), new UserMessage('Remove me'));

        $messageStore->clear($history->getKey());

        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, $nativeMessages, 'local-user'))->get($history->getKey());
        self::assertNotNull($reopened);
        self::assertSame(
            [],
            $reopened->getMessages(),
        );
    }
    #[DataProvider('storageKinds')]
    public function testImmediateRecordAndConversationUseTrackContentAndDates(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $sessions = new SessionStore($storage, $nativeMessages, 'owner');
        $session = $sessions->create(['projectId' => 'one']);
        self::assertNotNull($sessions->get($session->getKey()));
        self::assertFalse($session->hasContent());
        self::assertEquals($session->getCreatedAt(), $session->getLastUsedAt());
        $createdAt = $session->getCreatedAt();
        $agent = $session->bindToAgent((new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer'))));
        iterator_to_array((new Conversation($agent, $session))->sendInput(new UserMessage('Question')));
        self::assertTrue($session->hasContent());
        self::assertGreaterThanOrEqual($createdAt, $session->getLastUsedAt());
        self::assertEquals($createdAt, $session->getCreatedAt());
        self::assertCount(2, $session->getMessages());
        self::assertCount(1, $sessions->list());
    }

    #[DataProvider('storageKinds')]
    public function testConversationRegistersSavedInputAndStartsANewSession(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $messages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $sessions = new SessionStore($storage, $messages, 'owner');
        $session = $sessions->create();
        $created = $session->getCreatedAt();
        $conversation = new Conversation((new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer'), new AssistantMessage('Again'))), $session);
        $question = new UserMessage('Original question');
        iterator_to_array($conversation->sendInput($question));
        self::assertTrue($session->hasContent());
        self::assertEquals($created, $session->getCreatedAt());
        self::assertCount(1, $sessions->list());
        self::assertCount(2, $session->getMessages());
        $lastUsed = $session->getLastUsedAt();
        $conversation->setCommands(new Commands(new ClearCommand($sessions)));
        iterator_to_array($conversation->sendInput('/clear'));
        self::assertNotSame($session->getKey(), $conversation->session()->getKey());
        self::assertFalse($conversation->session()->hasContent());
        self::assertCount(2, $session->getMessages());
        self::assertCount(1, $sessions->list());
        self::assertEquals($lastUsed, $session->getLastUsedAt());
        $conversation->useSession($session);
        iterator_to_array($conversation->sendInput('Continue'));
        self::assertTrue($session->hasContent());
        self::assertCount(1, $sessions->list());
        $sessions->delete($session->getKey());
        self::assertSame([], $messages->loadAll($session->messageNamespace()));
        $this->expectException(MissingEntryException::class);
        iterator_to_array($conversation->sendInput('Deleted Session'));
    }

    #[DataProvider('storageKinds')]
    public function testSharedNativeMessageStoreIsolatesUsersWithTheSameSessionKey(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $messages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $alice = (new SessionStore($storage, $messages, 'alice'))->create();
        $record = $storage->get(Session::namespaceForUser('alice'), $alice->getKey());
        self::assertNotNull($record);
        $storage->create(Session::namespaceForUser('bob'), $record, $alice->getKey());
        $bob = (new SessionStore($storage, $messages, 'bob'))->get($alice->getKey());
        self::assertNotNull($bob);
        $alice->bindToAgent(new Agent())->getChatHistory()->addMessage(new UserMessage('Alice private'));
        $bob->bindToAgent(new Agent())->getChatHistory()->addMessage(new UserMessage('Bob private'));
        self::assertSame('Alice private', $alice->getMessages()[0]->getContent());
        self::assertSame('Bob private', $bob->getMessages()[0]->getContent());
        $alice->delete();
        $reopenedBob = (new SessionStore($storage, $messages, 'bob'))->get($bob->getKey());
        self::assertNotNull($reopenedBob);
        self::assertSame('Bob private', $reopenedBob->getMessages()[0]->getContent());
        self::assertFalse($reopenedBob->hasContent());
    }

    public function testDirectAgentUsePersistsMessagesWithoutUpdatingTheSessionRecord(): void
    {
        $sessions = new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'owner');
        $session = $sessions->create();
        $before = $session->getLastUsedAt();
        $agent = $session->bindToAgent((new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer'))));
        $agent->chat(new UserMessage('Question'));
        self::assertCount(2, $session->getMessages());
        self::assertFalse($session->hasContent());
        self::assertEquals($before, $session->getLastUsedAt());
        self::assertSame([], $sessions->list());
    }

    public function testBindingAnUnownedSessionFailsBeforeAccessingMessages(): void
    {
        $messages = $this->createMock(MessageStoreInterface::class);
        $messages->expects(self::never())->method('loadActive');
        $storage = new InMemoryStorage();
        $owned = (new SessionStore($storage, $messages, 'owner'))->create();
        $record = $storage->get(Session::namespaceForUser('owner'), $owned->getKey());
        self::assertNotNull($record);
        $session = new Session(
            storage: $storage,
            messageStore: $messages,
            key: 'foreign-thread',
            userId: 'owner',
            title: $owned->getTitle(),
            createdAt: $owned->getCreatedAt(),
            lastUsedAt: $owned->getLastUsedAt(),
            hasContent: $owned->hasContent(),
            metadata: $owned->getMetadata(),
        );
        $this->expectException(MissingEntryException::class);
        $session->bindToAgent(new Agent());
    }

    public function testCustomMessageStoreFailureDoesNotRegisterUse(): void
    {
        $messages = $this->createStub(MessageStoreInterface::class);
        $messages->method('append')->willThrowException(new RuntimeException('Persistence failed'));
        $sessions = new SessionStore(new InMemoryStorage(), $messages, 'owner');
        $session = $sessions->create();
        $lastUse = $session->getLastUsedAt();
        try {
            $session->bindToAgent(new Agent())->getChatHistory()->addMessage(new UserMessage('Not stored'));
            self::fail('Expected the message-store error.');
        } catch (RuntimeException $exception) {
            self::assertSame('Persistence failed', $exception->getMessage());
        }
        self::assertFalse($session->hasContent());
        self::assertEquals($lastUse, $session->getLastUsedAt());
        self::assertSame([], $sessions->list());
    }

    #[DataProvider('storageKinds')]
    public function testDuplicatePreservesArchivedPayloadAndOrderButRegistersUse(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $sessions = new SessionStore($storage, $nativeMessages, 'owner');
        $session = $sessions->create();
        $store = new SessionMessages($storage, $nativeMessages, 'owner');
        $original = (new UserMessage('Original'))->setMetadata(['float' => 1.0]);
        $store->append($session->getKey(), $original);
        $session = $sessions->get($session->getKey());
        self::assertNotNull($session);
        $lastUse = $session->getLastUsedAt();
        $store->archive($session->getKey(), 1);
        $replayed = (new UserMessage('Changed'))->setId($original->getId());
        $store->append($session->getKey(), $replayed);
        self::assertSame([], $store->loadActive($session->getKey()));
        self::assertCount(1, $session->getMessages());
        self::assertSame('Original', $session->getMessages()[0]->getContent());
        self::assertSame($original->getId(), $session->getMessages()[0]->getId());
        self::assertEquals(1.0, $session->getMessages()[0]->getMetadata('float'));
        $session = $sessions->get($session->getKey());
        self::assertNotNull($session);
        self::assertGreaterThan($lastUse, $session->getLastUsedAt());
        $lastUse = $session->getLastUsedAt();
        $store->archive($session->getKey(), 0);
        $store->archive($session->getKey(), -1);
        self::assertEquals($lastUse, $session->getLastUsedAt());
    }

    #[DataProvider('storageKinds')]
    public function testDirectAgentResetClearsMessagesWithoutUpdatingTheRecord(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $sessions = new SessionStore($storage, $nativeMessages, 'owner');
        $session = $sessions->create(['projectId' => 'one']);
        $session->setTitle('Saved title');
        $message = new UserMessage('Saved question');
        $store = new SessionMessages($storage, $nativeMessages, 'owner');
        $store->append($session->getKey(), $message);
        $session = $sessions->get($session->getKey());
        self::assertNotNull($session);
        $store->archive($session->getKey(), 1);
        $lastUse = $session->getLastUsedAt();
        $agent = $session->bindToAgent((new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer'))));
        $agent->resetConversation();
        self::assertSame([], $session->getMessages());
        self::assertTrue($session->hasContent());
        self::assertCount(1, $sessions->list());
        self::assertSame('Saved title', $session->getTitle());
        self::assertSame(['projectId' => 'one'], $session->getMetadata());
        self::assertEquals($lastUse, $session->getLastUsedAt());
        iterator_to_array((new Conversation($agent, $session))->sendInput($message));
        self::assertTrue($session->hasContent());
        self::assertCount(1, $sessions->list());
        self::assertSame($message->getId(), $session->getMessages()[0]->getId());
        $namespace = $session->messageNamespace();
        $sessions->delete($session->getKey());
        self::assertNull($sessions->get($session->getKey()));
        self::assertSame([], $nativeMessages->loadAll($namespace));
    }

    #[DataProvider('storageKinds')]
    public function testSummarizationClearAndReappendPersistsRetainedIds(bool $files): void
    {
        $nativeMessages = $files ? new FileMessageStore($this->directory . '/messages') : new InMemoryMessageStore();
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $session = (new SessionStore($storage, $nativeMessages, 'owner'))->create();
        $agent = $session->bindToAgent((new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Newest answer'))));
        $agent->setContextWindow(PHP_INT_MAX);
        $agent->addGlobalMiddleware(new Summarization(new FakeAIProvider(new AssistantMessage('Earlier topic')), maxTokens: 1, messagesToKeep: 1));
        $history = $agent->getChatHistory();
        $history->addMessage(new UserMessage('First question'));
        $history->addMessage((new AssistantMessage('First answer'))->setUsage(new Usage(100, 10)));
        $history->addMessage(new UserMessage('Second question'));
        $retained = (new AssistantMessage('Second answer'))->setUsage(new Usage(120, 10));
        $history->addMessage($retained);
        $latest = new UserMessage('Latest question');
        iterator_to_array((new Conversation($agent, $session))->sendInput($latest));
        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, $nativeMessages, 'owner'))->get($session->getKey());
        self::assertNotNull($reopened);
        $messages = $reopened->getMessages();
        self::assertCount(4, $messages);
        self::assertSame("## Previous conversation summary:\n\nEarlier topic", $messages[0]->getContent());
        self::assertSame($retained->getId(), $messages[1]->getId());
        self::assertSame($latest->getId(), $messages[2]->getId());
        self::assertSame('Newest answer', $messages[3]->getContent());
        self::assertTrue($reopened->hasContent());
    }

    public function testNonPersonMessagesNeverCreateOrResetContentVisibility(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, $nativeMessages, 'owner');
        $session = $sessions->create();
        $store = new SessionMessages($storage, $nativeMessages, 'owner');
        $store->append($session->getKey(), new ToolResultMessage([]));
        $store->append($session->getKey(), new UserMessage('  '));
        $store->append($session->getKey(), new AssistantMessage('Intro'));
        self::assertFalse($session->hasContent());
        self::assertSame([], $sessions->list());
        $store->append($session->getKey(), new UserMessage('Person content'));
        $store->append($session->getKey(), new ToolResultMessage([]));
        $store->append($session->getKey(), new AssistantMessage('Answer'));
        $session = $sessions->get($session->getKey());
        self::assertNotNull($session);
        self::assertTrue($session->hasContent());
        self::assertCount(1, $sessions->list());
    }

}
