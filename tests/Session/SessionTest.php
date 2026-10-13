<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Chat\Enums\SourceType;
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
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionMessageStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\MissingEntryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

use function array_map;
use function bin2hex;
use function is_dir;
use function is_link;
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
        $session = (new SessionStore(new InMemoryStorage(), 'local-user'))->create();

        self::assertSame([], $session->getMessages());
        self::assertNotSame('', $session->getKey());
        self::assertSame('local-user', $session->getUserId());
    }

    public function testReopeningDefersMessageValidationUntilMessagesAreRead(): void
    {
        $storage = new InMemoryStorage();
        $store = new SessionStore($storage, 'local-user');
        $created = $store->create();
        $storage->set($created->messageNamespace(), 'malformed', ['message' => [42], 'archived' => false]);
        $session = $store->get($created->getKey());

        self::assertNotNull($session);
        self::assertSame($created->getKey(), $session->getKey());
        self::assertSame('local-user', $session->getUserId());
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Stored message fields must have string names.');
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

        $messageStore = new SessionMessageStore($storage, 'local-user');
        $messageStore->append($history->getKey(), $question);
        $messageStore->append($history->getKey(), $answer);
        $messageStore->append($history->getKey(), new ToolCallMessage(tools: [$tool]));
        $messageStore->append($history->getKey(), new ToolResultMessage([$result]));

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
        $messageStore = new SessionMessageStore($storage, 'local-user');
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
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $history = (new SessionStore($storage, 'local-user'))->create();
        $messages = new SessionMessageStore($storage, 'local-user');
        $messages->append($history->getKey(), new UserMessage('Earlier question'));
        $messages->append($history->getKey(), new AssistantMessage('Earlier answer'));
        $question = 'Next question';
        $messages->append($history->getKey(), new UserMessage($question));
        $messages->archive($history->getKey(), 2);

        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, 'local-user'))->get($history->getKey());
        self::assertNotNull($reopened);
        self::assertCount(3, $reopened->getMessages());
        self::assertSame(
            [$question],
            array_map(
                static fn(Message $message): ?string => $message->getContent(),
                (new SessionMessageStore($files ? new FileStorage($this->directory) : $storage, 'local-user'))->loadActive($reopened->getKey()),
            ),
        );
    }

    #[DataProvider('storageKinds')]
    public function testClearingPersistsAnEmptyHistory(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $history = (new SessionStore($storage, 'local-user'))->create();
        $messageStore = new SessionMessageStore($storage, 'local-user');
        $messageStore->append($history->getKey(), new UserMessage('Remove me'));

        $messageStore->clear($history->getKey());

        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, 'local-user'))->get($history->getKey());
        self::assertNotNull($reopened);
        self::assertSame(
            [],
            $reopened->getMessages(),
        );
    }
    #[DataProvider('storageKinds')]
    public function testImmediateRecordAndDirectAgentUseTrackContentAndDates(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $sessions = new SessionStore($storage, 'owner');
        $session = $sessions->create(['projectId' => 'one']);
        self::assertNotNull($sessions->get($session->getKey()));
        self::assertFalse($session->hasContent());
        self::assertEquals($session->getCreatedAt(), $session->getLastUsedAt());
        $createdAt = $session->getCreatedAt();
        $agent = $session->bindToAgent((new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer'))));
        $agent->chat(new UserMessage('Question'));
        self::assertTrue($session->hasContent());
        self::assertGreaterThanOrEqual($createdAt, $session->getLastUsedAt());
        self::assertEquals($createdAt, $session->getCreatedAt());
        self::assertCount(2, $session->getMessages());
        self::assertCount(1, $sessions->list());
    }

    #[DataProvider('storageKinds')]
    public function testCustomMessageStoreRegistersDirectAgentUseAndClearWithoutRegisteringDuplicates(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $messages = new InMemoryMessageStore();
        $sessions = new SessionStore($storage, 'owner', $messages);
        $session = $sessions->create();
        $created = $session->getCreatedAt();
        $storage->update(Session::namespaceForUser('owner'), $session->getKey(), [
            ['path' => ['lastUsedAt'], 'value' => '2000-01-01T00:00:00.000000+00:00'],
        ]);
        $before = $session->getLastUsedAt();
        $agent = $session->bindToAgent((new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer'))));
        $question = new UserMessage('Original question');
        $agent->chat($question);
        self::assertTrue($session->hasContent());
        self::assertGreaterThan($before, $session->getLastUsedAt());
        self::assertEquals($created, $session->getCreatedAt());
        self::assertCount(1, $sessions->list());
        self::assertCount(2, $session->getMessages());
        $lastUsed = $session->getLastUsedAt();
        $messages->archive($session->messageNamespace(), 2);
        $replay = (new UserMessage('Changed replay'))->setId($question->getId());
        $agent->getChatHistory()->addMessage($replay);
        self::assertSame([], $messages->loadActive($session->messageNamespace()));
        self::assertSame('Original question', $session->getMessages()[0]->getContent());
        self::assertCount(2, $session->getMessages());
        self::assertEquals($lastUsed, $session->getLastUsedAt());

        $agent->resetConversation();
        self::assertSame([], $session->getMessages());
        self::assertFalse($session->hasContent());
        self::assertSame([], $sessions->list());
        self::assertEquals($lastUsed, $session->getLastUsedAt());
        $agent->getChatHistory()->addMessage($question);
        self::assertTrue($session->hasContent());
        self::assertCount(1, $sessions->list());
        $sessions->delete($session->getKey());
        self::assertSame([], $messages->loadAll($session->messageNamespace()));
        $this->expectException(MissingEntryException::class);
        $agent->getChatHistory()->addMessage(new UserMessage('Deleted Session'));
    }

    public function testSharedCustomMessageStoreIsolatesUsersWithTheSameSessionKey(): void
    {
        $storage = new InMemoryStorage();
        $messages = new InMemoryMessageStore();
        $alice = (new SessionStore($storage, 'alice', $messages))->create();
        $record = $storage->get(Session::namespaceForUser('alice'), $alice->getKey());
        self::assertNotNull($record);
        $storage->create(Session::namespaceForUser('bob'), $record, $alice->getKey());
        $bob = (new SessionStore($storage, 'bob', $messages))->get($alice->getKey());
        self::assertNotNull($bob);
        $alice->bindToAgent(new Agent())->getChatHistory()->addMessage(new UserMessage('Alice private'));
        $bob->bindToAgent(new Agent())->getChatHistory()->addMessage(new UserMessage('Bob private'));
        self::assertSame('Alice private', $alice->getMessages()[0]->getContent());
        self::assertSame('Bob private', $bob->getMessages()[0]->getContent());
        $alice->delete();
        $reopenedBob = (new SessionStore($storage, 'bob', $messages))->get($bob->getKey());
        self::assertNotNull($reopenedBob);
        self::assertSame('Bob private', $reopenedBob->getMessages()[0]->getContent());
        self::assertTrue($reopenedBob->hasContent());
    }

    public function testCustomMessageStoreRejectsRebindingToAnUnownedThreadBeforeDelegating(): void
    {
        $messages = $this->createMock(MessageStoreInterface::class);
        $messages->expects(self::never())->method('loadActive');
        $session = (new SessionStore(new InMemoryStorage(), 'owner', $messages))->create();
        $agent = $session->bindToAgent(new Agent())->for('foreign-thread');
        $this->expectException(UnexpectedValueException::class);
        $agent->getChatHistory()->getMessages();
    }

    public function testCustomMessageStoreFailureDoesNotRegisterUse(): void
    {
        $messages = $this->createStub(MessageStoreInterface::class);
        $messages->method('append')->willThrowException(new RuntimeException('Persistence failed'));
        $sessions = new SessionStore(new InMemoryStorage(), 'owner', $messages);
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
    public function testDuplicatePreservesArchivedPayloadOrderAndLastUse(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $sessions = new SessionStore($storage, 'owner');
        $session = $sessions->create();
        $store = new SessionMessageStore($storage, 'owner');
        $original = (new UserMessage('Original'))->setMetadata(['float' => 1.0]);
        $store->append($session->getKey(), $original);
        $lastUse = $session->getLastUsedAt();
        $store->archive($session->getKey(), 1);
        $replayed = (new UserMessage('Changed'))->setId($original->getId());
        $store->append($session->getKey(), $replayed);
        self::assertSame([], $store->loadActive($session->getKey()));
        self::assertCount(1, $session->getMessages());
        self::assertSame('Original', $session->getMessages()[0]->getContent());
        self::assertSame($original->getId(), $session->getMessages()[0]->getId());
        self::assertSame(1.0, $session->getMessages()[0]->getMetadata('float'));
        self::assertEquals($lastUse, $session->getLastUsedAt());
        $store->archive($session->getKey(), 0);
        $store->archive($session->getKey(), -1);
        self::assertEquals($lastUse, $session->getLastUsedAt());
    }

    #[DataProvider('storageKinds')]
    public function testResetClearsArchivedEntriesAndReappendRestoresVisibility(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $sessions = new SessionStore($storage, 'owner');
        $session = $sessions->create(['projectId' => 'one']);
        $session->setTitle('Saved title');
        $message = new UserMessage('Saved question');
        $store = new SessionMessageStore($storage, 'owner');
        $store->append($session->getKey(), $message);
        $store->archive($session->getKey(), 1);
        $lastUse = $session->getLastUsedAt();
        $agent = $session->bindToAgent(new Agent());
        $agent->resetConversation();
        self::assertSame([], $session->getMessages());
        self::assertFalse($session->hasContent());
        self::assertSame([], $sessions->list());
        self::assertSame('Saved title', $session->getTitle());
        self::assertSame(['projectId' => 'one'], $session->getMetadata());
        self::assertEquals($lastUse, $session->getLastUsedAt());
        $agent->getChatHistory()->addMessage($message);
        self::assertTrue($session->hasContent());
        self::assertCount(1, $sessions->list());
        self::assertSame($message->getId(), $session->getMessages()[0]->getId());
        $namespace = $session->messageNamespace();
        $sessions->delete($session->getKey());
        self::assertNull($sessions->get($session->getKey()));
        self::assertSame([], $storage->list($namespace));
    }

    #[DataProvider('storageKinds')]
    public function testSummarizationClearAndReappendPersistsRetainedIds(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $session = (new SessionStore($storage, 'owner'))->create();
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
        $agent->chat($latest);
        $reopened = (new SessionStore($files ? new FileStorage($this->directory) : $storage, 'owner'))->get($session->getKey());
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
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'owner');
        $session = $sessions->create();
        $store = new SessionMessageStore($storage, 'owner');
        $store->append($session->getKey(), new ToolResultMessage([]));
        $store->append($session->getKey(), new UserMessage('  '));
        $store->append($session->getKey(), new AssistantMessage('Intro'));
        self::assertFalse($session->hasContent());
        self::assertSame([], $sessions->list());
        $store->append($session->getKey(), new UserMessage('Person content'));
        $store->append($session->getKey(), new ToolResultMessage([]));
        $store->append($session->getKey(), new AssistantMessage('Answer'));
        self::assertTrue($session->hasContent());
        self::assertCount(1, $sessions->list());
    }

}
