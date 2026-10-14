<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\History\SessionMessages;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

use const PHP_INT_MAX;

final class SessionBindingTest extends TestCase
{
    public function testArchivingRetainsMessagesAndAppendIsIdempotent(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $session = (new SessionStore($storage, $nativeMessages, 'alice'))->create();
        $store = new SessionMessages($storage, $nativeMessages, 'alice');
        $threadId = $session->getKey();
        $first = new UserMessage('First');
        $second = new AssistantMessage('Second');
        $third = new UserMessage('Third');
        foreach ([$first, $second, $third, $first] as $message) {
            $store->append($threadId, $message);
        }
        $store->archive($threadId, 2);
        self::assertEquals([$third], $store->loadActive($threadId));
        self::assertEquals([$first, $second, $third], $store->loadAll($threadId));
        self::assertEquals([$second], $store->loadAll($threadId, limit: 1, before: $third->getId()));
        self::assertSame([], $store->loadAll($threadId, before: 'unknown'));
        $store->clear($threadId);
        self::assertSame([], $store->loadAll($threadId));
    }

    public function testMessageIdentitiesAndMetadataRemainStableAcrossReads(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $session = (new SessionStore($storage, $nativeMessages, 'alice'))->create();
        $store = new SessionMessages($storage, $nativeMessages, 'alice');
        $original = (new UserMessage('Earlier'))->setMetadata(['source' => 'original']);
        $store->append($session->getKey(), $original);
        $first = $store->loadActive($session->getKey())[0];
        self::assertSame($original->getId(), $first->getId());
        self::assertSame('original', $first->getMetadata('source'));
        $store->append($session->getKey(), $first);
        self::assertCount(1, $store->loadAll($session->getKey()));
        $store->append($session->getKey(), new AssistantMessage('New answer'));
        self::assertSame($first->getId(), $store->loadAll($session->getKey())[0]->getId());
        self::assertSame('original', $store->loadAll($session->getKey())[0]->getMetadata('source'));
    }

    public function testStructuredToolErrorsRoundTrip(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $session = (new SessionStore($storage, $nativeMessages, 'alice'))->create();
        $store = new SessionMessages($storage, $nativeMessages, 'alice');
        $call = (new ToolCall(name: 'lookup', callId: 'call-1'))->setResult(ToolOutput::error('Invalid input'));
        $store->append($session->getKey(), new ToolResultMessage([$call]));
        $message = $store->loadAll($session->getKey())[0];
        self::assertInstanceOf(ToolResultMessage::class, $message);
        $result = $message->getToolCalls()[0]->getResult();
        self::assertInstanceOf(ToolOutput::class, $result);
        self::assertTrue($result->isError());
        self::assertSame('Invalid input', $result->getText());
    }

    public function testStoreRejectsAnotherUsersThread(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $session = (new SessionStore($storage, $nativeMessages, 'bob'))->create();
        $store = new SessionMessages($storage, $nativeMessages, 'alice');
        $this->expectException(UnexpectedValueException::class);
        $store->append($session->getKey(), new UserMessage('Unauthorized'));
    }

    public function testBindingAnotherSessionReturnsAnAgentCopyAndKeepsTheOriginalConversation(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $sessions = new SessionStore(new InMemoryStorage(), $nativeMessages, 'alice');
        $first = $sessions->create();
        $second = $sessions->create();
        $provider = new FakeAIProvider(new AssistantMessage('First answer'), new AssistantMessage('Second answer'));
        $agent = $first->bindToAgent((new Agent())->setAiProvider($provider));
        $agent->chat(new UserMessage('First question'));
        $replacement = $second->bindToAgent($agent);
        self::assertNotSame($agent, $replacement);
        self::assertSame($first->messageNamespace(), $agent->getThreadId());
        self::assertSame($second->messageNamespace(), $replacement->getThreadId());
        $replacement->chat(new UserMessage('Second question'));
        self::assertCount(2, $agent->getChatHistory()->getMessages());
        self::assertCount(2, $replacement->getChatHistory()->getMessages());
        self::assertSame('First question', $agent->getChatHistory()->getMessages()[0]->getContent());
        self::assertSame('Second question', $replacement->getChatHistory()->getMessages()[0]->getContent());
    }
    public function testBindingPreservesTheAgentsContextWindowAndLeavesTheOriginalUnbound(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'alice'))->create();
        $provider = new FakeAIProvider();
        $original = (new Agent())->setAiProvider($provider)->setContextWindow(PHP_INT_MAX);
        $bound = $session->bindToAgent($original);
        self::assertNotSame($original, $bound);
        self::assertNull($original->getThreadId());
        self::assertSame($provider, $bound->getProvider());
        $history = $bound->getChatHistory();
        $history->addMessage(new UserMessage('First question'));
        $history->addMessage((new AssistantMessage('First answer'))->setUsage(new Usage(60000, 1000)));
        $history->addMessage(new UserMessage('Next question'));
        self::assertCount(3, $bound->getChatHistory()->getMessages());
        self::assertCount(3, $session->getMessages());
    }

    public function testArchivedConversationsRemainListedAndReadableThroughTheSession(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, $nativeMessages, 'alice');
        $session = $sessions->create();
        $store = new SessionMessages($storage, $nativeMessages, 'alice');
        $message = new UserMessage('Saved subject');
        $store->append($session->getKey(), $message);
        $store->archive($session->getKey(), 1);
        self::assertSame([], $session->bindToAgent(new Agent())->getChatHistory()->getMessages());
        self::assertEquals([$message], $session->getMessages());
        self::assertSame($session->getKey(), $sessions->list()[0]->getKey());
    }

    public function testAppendPropagatesFailuresWithoutReadingHistoryOrRegisteringUse(): void
    {
        foreach ([new InvalidArgumentException('invalid payload'), new RuntimeException('publication failure')] as $failure) {
            $storage = new InMemoryStorage();
            $messages = $this->createMock(MessageStoreInterface::class);
            $messages->expects(self::never())->method('loadAll');
            $messages->expects(self::once())->method('append')->willThrowException($failure);
            $sessions = new SessionStore($storage, $messages, 'owner');
            $session = $sessions->create();
            $lastUsed = $session->getLastUsedAt();
            $store = new SessionMessages($storage, $messages, 'owner');
            try {
                $store->append($session->getKey(), new UserMessage('Question'));
                self::fail('Message failures must propagate.');
            } catch (InvalidArgumentException|RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertFalse($session->hasContent());
            self::assertEquals($lastUsed, $session->getLastUsedAt());
            self::assertSame([], $sessions->list());
        }
    }

    public function testOwnershipIsRequiredForEveryMessageOperation(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $session = (new SessionStore($storage, $nativeMessages, 'bob'))->create();
        $owner = new SessionMessages($storage, $nativeMessages, 'bob');
        $owner->append($session->getKey(), new UserMessage('Owned content'));
        $stranger = new SessionMessages($storage, $nativeMessages, 'alice');
        foreach ([
            fn() => $stranger->loadActive($session->getKey()),
            fn() => $stranger->loadAll($session->getKey()),
            fn() => $stranger->append($session->getKey(), new UserMessage('Intrusion')),
            fn() => $stranger->archive($session->getKey(), 1),
            fn() => $stranger->clear($session->getKey()),
        ] as $operation) {
            try {
                $operation();
                self::fail('A foreign thread must be inaccessible.');
            } catch (UnexpectedValueException) {
                self::assertSame('Owned content', $owner->loadActive($session->getKey())[0]->getContent());
            }
        }
    }

}
