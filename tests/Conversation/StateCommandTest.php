<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\AgentChanged;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Event\SessionChanged;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\EventCollector;
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StateCommandTest extends TestCase
{
    public function testClearStartsANewSessionWithoutDeletingTheOldOneAndResumeRestoresIt(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner');
        $original = $store->create();
        $original->setTitle('Previous subject');
        SessionHistory::append($original, new UserMessage('Saved history'));
        $conversation = new Conversation(new Agent(), $original);
        $conversation->setCommands(new Commands(new ClearCommand($store, '/fresh'), new ResumeCommand($store)));
        $events = EventCollector::input($conversation, '/fresh');
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionChanged::class, $events[0]);
        self::assertSame($conversation->session(), $events[0]->session);
        self::assertNotSame($original->getKey(), $conversation->session()->getKey());
        self::assertSame($conversation->session()->messageNamespace(), $conversation->agent()->getThreadId());
        self::assertSame([], $conversation->session()->getMessages());
        self::assertSame('Saved history', $store->get($original->getKey())?->getMessages()[0]->getContent());
        EventCollector::input($conversation, '/resume ' . $original->getKey());
        self::assertSame($original->getKey(), $conversation->session()->getKey());
        self::assertSame('Previous subject', $conversation->session()->getTitle());
    }

    public function testStateChangesAreImmediateAndEventsPrecedeTheOriginalCommandFailure(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner');
        $selected = $store->create();
        $selected->setTitle('Selected');
        $replacement = (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('New answer')));
        $failure = new RuntimeException('Failed after changing state');
        $command = new class ($selected, $replacement, $failure) implements CommandInterface {
            public function __construct(private Session $selected, private Agent $replacement, private RuntimeException $failure) {}

            public function name(): string
            {
                return '/change';
            }

            public function describe(): string
            {
                return 'Change both states then fail';
            }

            public function run(CommandContext $context, string $value): void
            {
                $context->notify('Before');
                $context->useSession($this->selected);
                $context->session()->setMetadata('changed', 'yes');
                $context->useAgent($this->replacement);
                $context->notify($context->session()->getTitle() . ':' . $context->agent()->getThreadId());
                throw $this->failure;
            }
        };
        $conversation = new Conversation(new Agent(), $store->create());
        $conversation->setCommands(new Commands($command));
        $events = [];
        try {
            $conversation->runInput('/change', static function (object $event) use (&$events): void {
                $events[] = $event;
            });
            self::fail('Expected original command exception');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertCount(4, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertInstanceOf(SessionChanged::class, $events[1]);
        self::assertSame($conversation->session(), $events[1]->session);
        self::assertInstanceOf(AgentChanged::class, $events[2]);
        self::assertSame($conversation->agent(), $events[2]->agent);
        self::assertInstanceOf(Notification::class, $events[3]);
        self::assertSame('Selected:' . $selected->messageNamespace(), $events[3]->text);
        self::assertSame('yes', $store->get($selected->getKey())?->getMetadataValue('changed'));
        EventCollector::input($conversation, 'Next question', $stream);
        self::assertInstanceOf(AgentState::class, $stream);
        self::assertSame('New answer', $stream->getMessage()?->getContent());
        self::assertCount(2, $conversation->session()->getMessages());
    }

    public function testCommandCanSelectASessionProvidedByItsOwnStore(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner');
        $selected = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'other'))->create();
        $replacement = new Agent();
        $command = $this->switchCommand($selected, $replacement);
        $conversation = new Conversation(new Agent(), $store->create());
        $conversation->setCommands(new Commands($command));

        $events = EventCollector::input($conversation, '/switch');

        self::assertCount(2, $events);
        self::assertInstanceOf(SessionChanged::class, $events[0]);
        self::assertSame($selected, $events[0]->session);
        self::assertSame($selected, $conversation->session());
        self::assertInstanceOf(AgentChanged::class, $events[1]);
        self::assertSame($selected->messageNamespace(), $conversation->agent()->getThreadId());
    }

    public function testAnAlreadyStartedResponseRetainsItsAgentAndSessionWhenACommandSwitchesThem(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner');
        $original = $store->create();
        $selected = $store->create();
        $newAgent = (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Selected answer')));
        $oldAgent = (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Original answer')));
        $conversation = new Conversation($oldAgent, $original);
        $conversation->setCommands(new Commands($this->switchCommand($selected, $newAgent)));
        $events = [];
        $running = $conversation->runInput('Question before switch', static function (object $event) use ($conversation, &$events): void {
            if ($events === []) {
                $events = EventCollector::input($conversation, '/switch');
            }
        });
        self::assertCount(2, $events);
        self::assertInstanceOf(SessionChanged::class, $events[0]);
        self::assertInstanceOf(AgentChanged::class, $events[1]);
        self::assertInstanceOf(AgentState::class, $running);
        self::assertSame('Original answer', $running->getMessage()?->getContent());
        self::assertCount(2, $store->get($original->getKey())?->getMessages() ?? []);
        self::assertSame([], $conversation->session()->getMessages());
        EventCollector::input($conversation, 'Question after switch', $next);
        self::assertInstanceOf(AgentState::class, $next);
        self::assertSame('Selected answer', $next->getMessage()?->getContent());
        self::assertSame($selected->getKey(), $conversation->session()->getKey());
        self::assertCount(2, $conversation->session()->getMessages());
    }

    private function switchCommand(Session $session, Agent $agent): CommandInterface
    {
        return new class ($session, $agent) implements CommandInterface {
            public function __construct(private Session $selected, private Agent $replacement) {}

            public function name(): string
            {
                return '/switch';
            }

            public function describe(): string
            {
                return 'Select a Session and Agent';
            }

            public function run(CommandContext $context, string $value): void
            {
                $context->useSession($this->selected);
                $context->useAgent($this->replacement);
            }
        };
    }
}
