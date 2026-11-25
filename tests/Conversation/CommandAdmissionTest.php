<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\EventCollector;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CommandAdmissionTest extends TestCase
{
    public function testAdmissionIsReevaluatedForEveryInvocation(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $command = new AdmissionProbeCommand();
        $allowed = false;
        $admission = new AdmissionObservation();
        $provider = new FakeAIProvider(new AssistantMessage('Unused'));
        $conversation = new Conversation(
            (new Agent())->setAiProvider($provider),
            (new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner'))->create(),
            admitCommand: static function (CommandInterface $candidate) use (&$allowed, $admission): bool {
                $admission->record($candidate);
                return $allowed;
            },
        );
        $conversation->setCommands(new Commands($command));
        self::assertSame([], $admission->commands());
        self::assertSame([], $command->values);
        $events = EventCollector::input($conversation, '/probe rejected');
        self::assertSame([$command], $admission->commands());
        self::assertSame([], $command->values);
        self::assertNull($conversation->configurationStore()->get('effect'));
        self::assertSame([], $provider->getRecorded());
        self::assertCount(1, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame(NotificationLevel::Warning, $events[0]->level);
        self::assertNotSame('', $events[0]->text);
        $allowed = true;
        EventCollector::input($conversation, new CommandInput('/probe', 'selected'));
        self::assertSame([$command, $command], $admission->commands());
        self::assertSame(['selected'], $command->values);
        self::assertSame('selected', $conversation->configurationStore()->get('effect'));
        $allowed = false;
        EventCollector::input($conversation, new CommandInput('/probe', 'second choice'));
        self::assertSame(['selected'], $command->values);
        self::assertCount(3, $admission->commands());
    }

    public function testWithoutAdmissionOrdinaryCommandsAreAllowed(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $command = new AdmissionProbeCommand();
        $conversation = new Conversation(
            new Agent(),
            (new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner'))->create(),
        );
        $conversation->setCommands(new Commands($command));
        EventCollector::input($conversation, '/probe ordinary');
        self::assertSame(['ordinary'], $command->values);
    }

    public function testHostCanRefuseHelpRegardlessOfTheCommandClass(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $conversation = new Conversation(
            new Agent(),
            (new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner'))->create(),
            admitCommand: static fn(CommandInterface $command): bool => false,
        );
        $conversation->setCommands(new Commands(new HelpCommand()));
        $events = EventCollector::input($conversation, '/help');
        self::assertCount(1, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame(NotificationLevel::Warning, $events[0]->level);
    }

    public function testAdmissionFailurePropagatesWithoutRunningTheCommand(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $command = new AdmissionProbeCommand();
        $failure = new RuntimeException('Authorization unavailable');
        $conversation = new Conversation(
            new Agent(),
            (new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner'))->create(),
            admitCommand: static function (CommandInterface $candidate) use ($failure): bool {
                throw $failure;
            },
        );
        $conversation->setCommands(new Commands($command));
        try {
            EventCollector::input($conversation, '/probe');
            self::fail('Expected the admission exception');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertSame([], $command->values);
        self::assertNull($conversation->configurationStore()->get('effect'));
    }

    public function testUnknownCommandDoesNotInvokeAdmission(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $conversation = new Conversation(
            new Agent(),
            (new SessionStore(new InMemoryStorage(), $nativeMessages, 'owner'))->create(),
            admitCommand: static function (CommandInterface $candidate): bool {
                self::fail('An unknown Command has nothing to admit');
            },
        );
        $events = EventCollector::input($conversation, '/missing');
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame(NotificationLevel::Error, $events[0]->level);
    }
}

final class AdmissionProbeCommand implements CommandInterface
{
    /** @var list<string> */
    public array $values = [];

    public function name(): string
    {
        return '/probe';
    }

    public function describe(): string
    {
        return 'Ordinary state-changing command';
    }

    public function run(CommandContext $context, string $value): void
    {
        $this->values[] = $value;
        $context->configurationStore()->set('effect', $value);
    }
}

final class AdmissionObservation
{
    /** @var list<CommandInterface> */
    private array $commands = [];

    public function record(CommandInterface $command): void
    {
        $this->commands[] = $command;
    }

    /** @return list<CommandInterface> */
    public function commands(): array
    {
        return $this->commands;
    }
}
