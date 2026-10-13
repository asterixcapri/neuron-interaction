<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronInteraction\Session\SessionMessageStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryEntryStorage;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

use const PHP_INT_MAX;

final class SessionMessageStoreTest extends TestCase
{
    public function testArchivingRetainsMessagesAndAppendIsIdempotent(): void
    {
        $storage = new InMemoryEntryStorage();
        $session = (new SessionStore($storage, 'alice'))->create();
        $store = new SessionMessageStore($storage, 'alice');
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
        $storage = new InMemoryEntryStorage();
        $session = (new SessionStore($storage, 'alice'))->create();
        $store = new SessionMessageStore($storage, 'alice');
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
        $storage = new InMemoryEntryStorage();
        $session = (new SessionStore($storage, 'alice'))->create();
        $store = new SessionMessageStore($storage, 'alice');
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
        $storage = new InMemoryEntryStorage();
        $session = (new SessionStore($storage, 'bob'))->create();
        $store = new SessionMessageStore($storage, 'alice');
        $this->expectException(UnexpectedValueException::class);
        $store->append($session->getKey(), new UserMessage('Unauthorized'));
    }

    public function testBindingAnotherSessionReturnsAnAgentCopyAndKeepsTheOriginalConversation(): void
    {
        $sessions = new SessionStore(new InMemoryEntryStorage(), 'alice');
        $first = $sessions->create();
        $second = $sessions->create();
        $provider = new FakeAIProvider(new AssistantMessage('First answer'), new AssistantMessage('Second answer'));
        $agent = $first->bindToAgent((new Agent())->setAiProvider($provider));
        $agent->chat(new UserMessage('First question'));
        $replacement = $second->bindToAgent($agent);
        self::assertNotSame($agent, $replacement);
        self::assertSame($first->getKey(), $agent->getThreadId());
        self::assertSame($second->getKey(), $replacement->getThreadId());
        $replacement->chat(new UserMessage('Second question'));
        self::assertCount(2, $agent->getChatHistory()->getMessages());
        self::assertCount(2, $replacement->getChatHistory()->getMessages());
        self::assertSame('First question', $agent->getChatHistory()->getMessages()[0]->getContent());
        self::assertSame('Second question', $replacement->getChatHistory()->getMessages()[0]->getContent());
    }
    public function testBindingPreservesTheAgentsContextWindowAndLeavesTheOriginalUnbound(): void
    {
        $session = (new SessionStore(new InMemoryEntryStorage(), 'alice'))->create();
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
        $storage = new InMemoryEntryStorage();
        $sessions = new SessionStore($storage, 'alice');
        $session = $sessions->create();
        $store = new SessionMessageStore($storage, 'alice');
        $message = new UserMessage('Saved subject');
        $store->append($session->getKey(), $message);
        $store->archive($session->getKey(), 1);
        self::assertSame([], $session->bindToAgent(new Agent())->getChatHistory()->getMessages());
        self::assertEquals([$message], $session->getMessages());
        self::assertSame($session->getKey(), $sessions->list()[0]->getKey());
    }

}
