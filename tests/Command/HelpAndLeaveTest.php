<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitRequest;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Conversation;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

final class HelpAndLeaveTest extends TestCase
{
    public function testHelpListsTheRegisteredCommandsInOrder(): void
    {
        $conversation = new Conversation(
            new Agent(),
            (new SessionStore(new InMemoryStorage(), 'owner'))->create(),
        );
        $conversation->setCommands(new Commands(new HelpCommand('/guide'), new LeaveCommand('/quit')));
        $stream = $conversation->sendInput('/guide');
        $events = iterator_to_array($stream);
        self::assertCount(2, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertInstanceOf(Notification::class, $events[1]);
        self::assertSame('/guide — Lists what can be typed here.', $events[0]->text);
        self::assertSame('/quit — Stops the interaction.', $events[1]->text);
        self::assertNull($stream->getReturn());
    }

    public function testExitCanBeIgnoredWithoutEndingConversationOrStoppingResponse(): void
    {
        $stop = new StopSignal(new InMemoryStorage(), 'response');
        $provider = new FakeAIProvider(new AssistantMessage('Still available'));
        $conversation = new Conversation(
            (new Agent())->setAiProvider($provider),
            (new SessionStore(new InMemoryStorage(), 'owner'))->create(),
            stopSignal: $stop,
        );
        $conversation->setCommands(new Commands(new LeaveCommand(), new HelpCommand()));
        $session = $conversation->session();
        $events = iterator_to_array($conversation->sendInput('/exit'));
        self::assertCount(1, $events);
        self::assertInstanceOf(ExitRequest::class, $events[0]);
        self::assertFalse($stop->isRequested());
        self::assertFalse($conversation->responseStopRequested());
        self::assertSame($session, $conversation->session());
        self::assertCount(2, iterator_to_array($conversation->sendInput('/help')));
        iterator_to_array($conversation->sendInput('Continue after exit'));
        self::assertCount(1, $provider->getRecorded());
    }

    public function testExitPreservesTheOrderAndDoesNotCancelLaterRequests(): void
    {
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
            (new SessionStore(new InMemoryStorage(), 'owner'))->create(),
        );
        $conversation->setCommands(new Commands($command));
        $events = iterator_to_array($conversation->sendInput('/ordered'));
        self::assertCount(3, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame('Before', $events[0]->text);
        self::assertInstanceOf(ExitRequest::class, $events[1]);
        self::assertInstanceOf(Notification::class, $events[2]);
        self::assertSame('After', $events[2]->text);
    }
}
