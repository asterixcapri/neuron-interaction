<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\ExitRequest;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Exception\ExecutionAbortedException;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\EventCollector;
use PHPUnit\Framework\TestCase;

final class HelpAndExitTest extends TestCase
{
    public function testHelpListsTheRegisteredCommandsInOrder(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $conversation = new Conversation(
            new Agent(),
            (new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner'))->create(),
        );
        $conversation->setCommands(new Commands(new HelpCommand('/guide'), new ExitCommand('/quit')));
        $events = EventCollector::input($conversation, '/guide');
        self::assertCount(2, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertInstanceOf(Notification::class, $events[1]);
        self::assertSame('/guide — Lists what can be typed here.', $events[0]->text);
        self::assertSame('/quit — Stops the interaction.', $events[1]->text);
    }

    public function testExitCanBeIgnoredWithoutEndingConversationOrStoppingResponse(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $stop = new StopSignal(new InMemoryStorage(), 'response');
        $provider = new FakeAIProvider(new AssistantMessage('Still available'));
        $conversation = new Conversation(
            (new Agent())->setAiProvider($provider),
            (new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner'))->create(),
        );
        $conversation->setStopSignal($stop);
        $conversation->setCommands(new Commands(new ExitCommand(), new HelpCommand()));
        $session = $conversation->session();
        $events = EventCollector::input($conversation, '/exit');
        self::assertCount(1, $events);
        self::assertInstanceOf(ExitRequest::class, $events[0]);
        self::assertFalse($stop->isRequested());
        self::assertFalse($conversation->responseStopRequested());
        self::assertSame($session, $conversation->session());
        self::assertCount(2, EventCollector::input($conversation, '/help'));
        EventCollector::input($conversation, 'Continue after exit');
        self::assertCount(1, $provider->getRecorded());
    }

    public function testCallerCanAbortAtExitBeforeLaterEventsOrPrompts(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Must not run'));
        $command = new class implements CommandInterface {
            public function name(): string
            {
                return '/leave';
            }
            public function describe(): string
            {
                return 'Exit before a prompt';
            }
            public function run(CommandContext $context, string $value): void
            {
                $context->requestExit();
                $context->notify('Must not appear');
                $context->promptAgent(new UserMessage('Must not run'));
            }
        };
        $conversation = new Conversation((new Agent())->setAiProvider($provider), (new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'owner'))->create());
        $conversation->setCommands(new Commands($command));
        $events = [];
        try {
            $conversation->runInput('/leave', static function (object $event) use (&$events): void {
                $events[] = $event;
                throw new ExecutionAbortedException('Exit accepted');
            });
            self::fail('Expected caller abort.');
        } catch (ExecutionAbortedException $error) {
            self::assertSame('Exit accepted', $error->getMessage());
        }
        self::assertCount(1, $events);
        self::assertInstanceOf(ExitRequest::class, $events[0]);
        self::assertSame([], $provider->getRecorded());
    }

    public function testExitPreservesTheOrderAndDoesNotCancelLaterRequests(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $command = new class implements CommandInterface {
            public function name(): string
            {
                return '/ordered';
            }

            public function describe(): string
            {
                return 'Request exit between notices';
            }

            public function run(CommandContext $context, string $value): void
            {
                $context->notify('Before');
                $context->requestExit();
                $context->notify('After');
            }
        };
        $conversation = new Conversation(
            new Agent(),
            (new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner'))->create(),
        );
        $conversation->setCommands(new Commands($command));
        $events = EventCollector::input($conversation, '/ordered');
        self::assertCount(3, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame('Before', $events[0]->text);
        self::assertInstanceOf(ExitRequest::class, $events[1]);
        self::assertInstanceOf(Notification::class, $events[2]);
        self::assertSame('After', $events[2]->text);
    }
}
