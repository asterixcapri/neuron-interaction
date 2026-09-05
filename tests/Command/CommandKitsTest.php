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
    public function testIncrementalMountingMutatesTheOriginalCollectionInOrder(): void
    {
        $first = new ClearCommand('/resume');
        $last = new ClearCommand('/last');
        $kit = new SessionCommandKit();
        $commands = new Commands();

        self::assertSame($commands, $commands->addCommand($first));
        self::assertSame($commands, $commands->addCommand([$kit, $last]));
        self::assertSame(['/resume', '/clear', '/resume', '/last'], array_map(
            static fn (CommandInterface $command): string => $command->name(),
            $commands->all(),
        ));
        self::assertSame($last, $commands->all()[3]);
        self::assertSame($first, $commands->named('/resume'));
        $adapter = new FakeCommandAdapter($commands);
        $previous = $adapter->agent()->getChatHistory();
        self::assertSame('completed', $commands->run('/resume', new CommandArguments(), $adapter)?->status);
        self::assertNotSame($previous, $adapter->agent()->getChatHistory());
    }

    public function testConstructorAndIncrementalMountingApplyTheSameValidation(): void
    {
        foreach ([
            [new stdClass()],
            [new ObjectKit([new stdClass()])],
            new ClearCommand('missing-slash'),
        ] as $invalid) {
            foreach ([false, true] as $incremental) {
                try {
                    if ($incremental) {
                        (new Commands())->addCommand($invalid);
                    } else {
                        new Commands($invalid);
                    }
                    self::fail('Invalid Commands must fail when mounted.');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('mounted Command', $exception->getMessage());
                }
            }
        }
    }

    public function testSessionCommandKitMountsInOneOperationAndClearPreservesThePreviousSession(): void
    {
        $commands = new Commands(new SessionCommandKit());
        $adapter = new FakeCommandAdapter($commands);
        $previous = $adapter->sessions()->start();
        $previous->addMessage(new UserMessage('Keep this conversation'));
        $adapter->agent()->setChatHistory($previous);
        $key = $adapter->sessions()->summaries()[0]->key;

        $execution = $commands->run('/clear', new CommandArguments(), $adapter);

        self::assertSame(['/clear', '/resume'], array_map(
            static fn (CommandInterface $command): string => $command->name(),
            $commands->all(),
        ));
        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertNotSame($previous, $adapter->agent()->getChatHistory());
        self::assertSame([], $adapter->agent()->getChatHistory()->getMessages());
        self::assertSame('Keep this conversation', $adapter->sessions()->resume($key)->getMessages()[0]->getContent());
    }

    public function testMixedMountingPreservesOrderAndTheFirstDuplicateExecutes(): void
    {
        $first = new ClearCommand('/resume');
        $last = new ClearCommand('/last');
        $commands = new Commands([$first, new SessionCommandKit(), $last]);
        $adapter = new FakeCommandAdapter($commands);
        $previous = $adapter->sessions()->start();
        $adapter->agent()->setChatHistory($previous);

        self::assertSame(['/resume', '/clear', '/resume', '/last'], array_map(
            static fn (CommandInterface $command): string => $command->name(),
            $commands->all(),
        ));
        self::assertSame($first, $commands->named('/resume'));
        self::assertSame($last, $commands->named('/last'));
        self::assertSame('completed', $commands->run('/resume', new CommandArguments(), $adapter)?->status);
        self::assertNotSame($previous, $adapter->agent()->getChatHistory());
        self::assertSame([], $adapter->warnings);
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
        $adapter = new FakeCommandAdapter($commands);

        self::assertSame('completed', $commands->run('/resume', new CommandArguments(), $adapter)?->status);
        self::assertSame(['There is no earlier Session to return to yet.'], $adapter->warnings);
        self::assertSame([], $adapter->selections);
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
