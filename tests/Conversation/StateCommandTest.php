<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\AgentChanged;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SessionChanged;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function iterator_to_array;

final class StateCommandTest extends TestCase
{
    public function testClearStartsANewSessionWithoutDeletingTheOldOneAndResumeRestoresIt(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'owner');
        $original = $store->create();
        $original->setTitle('Previous subject');
        SessionHistory::of($original)->addMessage(new UserMessage('Saved history'));
        $conversation = new Conversation(new Agent(), $original, commands: new Commands(new ClearCommand($store, '/fresh'), new ResumeCommand($store)));
        $events = iterator_to_array($conversation->sendInput('/fresh'));
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionChanged::class, $events[0]);
        self::assertSame($conversation->session(), $events[0]->session);
        self::assertNotSame($original->getKey(), $conversation->session()->getKey());
        self::assertSame($conversation->session()->getKey(), $conversation->agent()->getThreadId());
        self::assertSame([], $conversation->session()->getMessages());
        self::assertSame('Saved history', $store->get($original->getKey())?->getMessages()[0]->getContent());
        iterator_to_array($conversation->sendInput('/resume ' . $original->getKey()));
        self::assertSame($original->getKey(), $conversation->session()->getKey());
        self::assertSame('Previous subject', $conversation->session()->getTitle());
    }

    public function testStateChangesAreImmediateAndEventsPrecedeTheOriginalCommandFailure(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'owner');
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
        $conversation = new Conversation(new Agent(), $store->create(), commands: new Commands($command));
        $events = [];
        try {
            foreach ($conversation->sendInput('/change') as $event) {
                $events[] = $event;
            }
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
        self::assertSame('Selected:' . $selected->getKey(), $events[3]->text);
        self::assertSame('yes', $store->get($selected->getKey())?->getMetadata()['changed']);
        $stream = $conversation->sendInput('Next question');
        iterator_to_array($stream);
        self::assertInstanceOf(AgentState::class, $stream->getReturn());
        self::assertSame('New answer', $stream->getReturn()->getMessage()?->getContent());
        self::assertCount(2, $conversation->session()->getMessages());
    }

    public function testCommandCanSelectASessionProvidedByItsOwnStore(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'owner');
        $selected = (new SessionStore(new InMemoryStorage(), 'other'))->create();
        $replacement = new Agent();
        $command = $this->switchCommand($selected, $replacement);
        $conversation = new Conversation(new Agent(), $store->create(), commands: new Commands($command));

        $events = iterator_to_array($conversation->sendInput('/switch'));

        self::assertCount(2, $events);
        self::assertInstanceOf(SessionChanged::class, $events[0]);
        self::assertSame($selected, $events[0]->session);
        self::assertSame($selected, $conversation->session());
        self::assertInstanceOf(AgentChanged::class, $events[1]);
        self::assertSame($selected->getKey(), $conversation->agent()->getThreadId());
    }

    public function testAnAlreadyStartedResponseRetainsItsAgentAndSessionWhenACommandSwitchesThem(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'owner');
        $original = $store->create();
        $selected = $store->create();
        $newAgent = (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Selected answer')));
        $oldAgent = (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Original answer')));
        $conversation = new Conversation($oldAgent, $original, commands: new Commands($this->switchCommand($selected, $newAgent)));
        $running = $conversation->sendInput('Question before switch');
        $running->rewind();
        $events = iterator_to_array($conversation->sendInput('/switch'));
        self::assertCount(2, $events);
        self::assertInstanceOf(SessionChanged::class, $events[0]);
        self::assertInstanceOf(AgentChanged::class, $events[1]);
        iterator_to_array($running);
        self::assertInstanceOf(AgentState::class, $running->getReturn());
        self::assertSame('Original answer', $running->getReturn()->getMessage()?->getContent());
        self::assertCount(2, $store->get($original->getKey())?->getMessages() ?? []);
        self::assertSame([], $conversation->session()->getMessages());
        $next = $conversation->sendInput('Question after switch');
        iterator_to_array($next);
        self::assertInstanceOf(AgentState::class, $next->getReturn());
        self::assertSame('Selected answer', $next->getReturn()->getMessage()?->getContent());
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
