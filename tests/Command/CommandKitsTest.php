<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\AbstractCommandKit;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SessionCommandKit;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CommandKitsTest extends TestCase
{
    public function testSessionCommandKitMountsInOneOperationAndClearPreservesThePreviousSession(): void
    {
        $commands = new Commands(new SessionCommandKit());
        $controls = new FakeCommandControls($commands);
        $previous = $controls->sessions()->start();
        $previous->addMessage(new UserMessage('Keep this conversation'));
        $controls->agent()->setChatHistory($previous);
        $key = $controls->sessions()->list()[0]->key;

        $execution = $commands->run('/clear', new CommandArguments(), $controls);

        self::assertSame(['/clear', '/resume'], array_map(
            static fn (CommandInterface $command): string => $command->name(),
            $commands->all(),
        ));
        self::assertSame('completed', $execution->status);
        self::assertNotSame($previous, $controls->agent()->getChatHistory());
        self::assertSame([], $controls->agent()->getChatHistory()->getMessages());
        self::assertSame('Keep this conversation', $controls->sessions()->resume($key)->getMessages()[0]->getContent());
    }

    public function testMixedMountingPreservesOrderAndTheFirstDuplicateExecutes(): void
    {
        $first = new ClearCommand('/resume');
        $last = new ClearCommand('/last');
        $commands = new Commands([$first, new SessionCommandKit(), $last]);
        $controls = new FakeCommandControls($commands);
        $previous = $controls->sessions()->start();
        $controls->agent()->setChatHistory($previous);

        self::assertSame(['/resume', '/clear', '/resume', '/last'], array_map(
            static fn (CommandInterface $command): string => $command->name(),
            $commands->all(),
        ));
        self::assertSame($first, $commands->named('/resume'));
        self::assertSame($last, $commands->named('/last'));
        self::assertSame('completed', $commands->run('/resume', new CommandArguments(), $controls)->status);
        self::assertNotSame($previous, $controls->agent()->getChatHistory());
        self::assertSame([], $controls->warnings);
    }

    public function testKitFiltersAreImmutableAndExclusionWins(): void
    {
        $kit = new SessionCommandKit();
        $resumeOnly = $kit->exclude([ClearCommand::class]);
        $clearOnly = $kit->only([ClearCommand::class]);

        self::assertInstanceOf(ResumeCommand::class, (new Commands($resumeOnly))->all()[0]);
        self::assertCount(1, (new Commands($resumeOnly))->all());
        self::assertInstanceOf(ClearCommand::class, (new Commands($clearOnly))->all()[0]);
        self::assertCount(1, (new Commands($clearOnly))->all());
        self::assertCount(2, (new Commands($kit))->all());
        self::assertSame([], (new Commands($clearOnly->exclude([ClearCommand::class])))->all());
        self::assertInstanceOf(ResumeCommand::class, (new Commands($clearOnly->only([ResumeCommand::class])))->all()[0]);
        self::assertSame([], (new Commands($kit->exclude([CommandInterface::class])))->all());
    }

    public function testInvalidKitMembersAreRejectedAtMounting(): void
    {
        $kit = new ObjectKit([new stdClass()]);

        $this->expectException(InvalidArgumentException::class);
        new Commands([$kit]);
    }

    public function testInvalidArrayMembersAreRejectedAtMounting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Commands([new stdClass()]);
    }

    public function testResumeWithNoStoredSessionWarnsWithoutRequestingSelection(): void
    {
        $commands = new Commands(new SessionCommandKit());
        $controls = new FakeCommandControls($commands);

        self::assertSame('completed', $commands->run('/resume', new CommandArguments(), $controls)->status);
        self::assertSame(['There is no earlier Session to return to yet.'], $controls->warnings);
        self::assertSame([], $controls->selections);
    }
}

/** @extends AbstractCommandKit<object> */
final class ObjectKit extends AbstractCommandKit
{
    /** @param list<object> $members */
    public function __construct(private array $members)
    {
    }

    /** @return list<object> */
    protected function provide(): array
    {
        return $this->members;
    }
}
