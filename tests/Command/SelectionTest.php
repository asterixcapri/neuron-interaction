<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Event\SelectionRequest;
use NeuronInteraction\Event\SessionChanged;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

final class SelectionTest extends TestCase
{
    public function testResumeRequestsSelectionThenInstallsTheChosenHistoryOnlyOnTheSecondInvocation(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner');
        $stored = $store->create();
        SessionHistory::append($stored, new UserMessage('Stored subject'));
        $active = $store->create();
        $conversation = new Conversation(new Agent(), $active);
        $conversation->setCommands(new Commands(new ResumeCommand($store, '/return')));
        $events = iterator_to_array($conversation->sendInput('/return'));

        self::assertSame($active->messageNamespace(), $conversation->agent()->getThreadId());
        self::assertSame($active->getKey(), $conversation->session()->getKey());
        self::assertCount(1, $events);
        self::assertInstanceOf(SelectionRequest::class, $events[0]);
        $request = $events[0];
        self::assertSame('/return', $request->command);
        self::assertSame($stored->getKey(), $request->options[0]->value);
        self::assertSame('Untitled session', $request->options[0]->label);
        self::assertNotNull($request->options[0]->description);

        $events = iterator_to_array($conversation->sendInput(new CommandInput($request->command, $request->options[0]->value)));
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionChanged::class, $events[0]);
        self::assertSame($conversation->session(), $events[0]->session);
        self::assertSame('Stored subject', $conversation->agent()->getChatHistory()->getMessages()[0]->getContent());
        self::assertSame($stored->getKey(), $conversation->session()->getKey());
    }

    public function testResumeNormalizesItsOwnWhitespaceArguments(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner');
        $stored = $store->create();
        SessionHistory::append($stored, new UserMessage('Stored subject'));
        $conversation = new Conversation(new Agent(), $store->create());
        $conversation->setCommands(new Commands(new ResumeCommand($store)));
        $selection = iterator_to_array($conversation->sendInput('/resume   '));
        self::assertCount(1, $selection);
        self::assertInstanceOf(SelectionRequest::class, $selection[0]);
        $events = iterator_to_array($conversation->sendInput('/resume  ' . $stored->getKey() . ' '));
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionChanged::class, $events[0]);
        self::assertSame($stored->getKey(), $conversation->session()->getKey());
    }

    public function testResumeWithAKeyNeedsNoPriorSelectionAndUnknownKeysLeaveTheSessionUnchanged(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $storage = new InMemoryStorage();
        $store = new SessionStore($storage, $nativeMessages, 'owner');
        $stored = $store->create();
        SessionHistory::append($stored, new UserMessage('Direct resume'));
        $foreign = (new SessionStore($storage, $nativeMessages, 'other'))->create();
        $conversation = new Conversation(new Agent(), $store->create());
        $conversation->setCommands(new Commands(new ResumeCommand($store)));
        $events = iterator_to_array($conversation->sendInput(new CommandInput('/resume', $stored->getKey())));
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionChanged::class, $events[0]);
        self::assertSame('Direct resume', $conversation->agent()->getChatHistory()->getMessages()[0]->getContent());
        self::assertSame($stored->getKey(), $conversation->session()->getKey());

        foreach (['unknown', $foreign->getKey()] as $key) {
            $session = $conversation->session();
            $agent = $conversation->agent();
            $events = iterator_to_array($conversation->sendInput(new CommandInput('/resume', $key)));
            self::assertCount(1, $events);
            self::assertInstanceOf(Notification::class, $events[0]);
            self::assertSame(NotificationLevel::Error, $events[0]->level);
            self::assertSame('No Session is named by that key.', $events[0]->text);
            self::assertSame($session, $conversation->session());
            self::assertSame($agent, $conversation->agent());
        }
    }

    public function testResumeWithoutStoredHistoryNotifiesInsteadOfRequestingAnEmptySelection(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner');
        $conversation = new Conversation(new Agent(), $store->create());
        $conversation->setCommands(new Commands(new ResumeCommand($store)));
        $events = iterator_to_array($conversation->sendInput('/resume'));
        self::assertCount(1, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame(NotificationLevel::Warning, $events[0]->level);
    }
}
