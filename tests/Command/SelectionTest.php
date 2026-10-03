<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Command\SessionChanged;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

final class SelectionTest extends TestCase
{
    public function testResumeRequestsSelectionThenInstallsTheChosenHistoryOnlyOnTheSecondInvocation(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'owner');
        $stored = $store->create();
        SessionHistory::of($stored)->addMessage(new UserMessage('Stored subject'));
        $active = $store->create();
        $conversation = new Conversation(new Agent(), $store, $active, commands: new Commands(new ResumeCommand('/return')));
        $events = iterator_to_array($conversation->submitInput('/return'));

        self::assertSame($active->getKey(), $conversation->agent()->getThreadId());
        self::assertSame($active->getKey(), $conversation->session()->getKey());
        self::assertCount(1, $events);
        self::assertInstanceOf(SelectionRequest::class, $events[0]);
        $request = $events[0];
        self::assertSame('/return', $request->command);
        self::assertSame($stored->getKey(), $request->options[0]->value);
        self::assertSame('New session', $request->options[0]->label);
        self::assertNotNull($request->options[0]->description);

        $events = iterator_to_array($conversation->submitInput(new CommandInput($request->command, $request->options[0]->value)));
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionChanged::class, $events[0]);
        self::assertSame($conversation->session(), $events[0]->session);
        self::assertSame('Stored subject', $conversation->agent()->getChatHistory()->getMessages()[0]->getContent());
        self::assertSame($stored->getKey(), $conversation->session()->getKey());
    }

    public function testResumeWithAKeyNeedsNoPriorSelectionAndUnknownKeysLeaveTheSessionUnchanged(): void
    {
        $storage = new InMemoryStorage();
        $store = new SessionStore($storage, 'owner');
        $stored = $store->create();
        SessionHistory::of($stored)->addMessage(new UserMessage('Direct resume'));
        $foreign = (new SessionStore($storage, 'other'))->create();
        $conversation = new Conversation(new Agent(), $store, commands: new Commands(new ResumeCommand()));
        $events = iterator_to_array($conversation->submitInput(new CommandInput('/resume', $stored->getKey())));
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionChanged::class, $events[0]);
        self::assertSame('Direct resume', $conversation->agent()->getChatHistory()->getMessages()[0]->getContent());
        self::assertSame($stored->getKey(), $conversation->session()->getKey());

        foreach (['unknown', $foreign->getKey()] as $key) {
            $session = $conversation->session();
            $agent = $conversation->agent();
            $events = iterator_to_array($conversation->submitInput(new CommandInput('/resume', $key)));
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
        $conversation = new Conversation(new Agent(), new SessionStore(new InMemoryStorage(), 'owner'), commands: new Commands(new ResumeCommand()));
        $events = iterator_to_array($conversation->submitInput('/resume'));
        self::assertCount(1, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame(NotificationLevel::Warning, $events[0]->level);
    }
}
