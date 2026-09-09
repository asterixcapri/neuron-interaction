<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CommandMountingTest extends TestCase
{
    public function testIncrementalMountingMutatesTheOriginalCollectionInOrder(): void
    {
        $first = new ClearCommand('/resume');
        $last = new ClearCommand('/last');
        $commands = new Commands();

        self::assertSame($commands, $commands->addCommand($first));
        self::assertSame($commands, $commands->addCommand([new ClearCommand(), new ResumeCommand(), $last]));
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
            [[new ClearCommand()]],
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

    public function testSessionCommandsMountTogetherAndClearPreservesThePreviousSession(): void
    {
        $commands = new Commands([new ClearCommand(), new ResumeCommand()]);
        $adapter = new FakeCommandAdapter($commands);
        $previous = $adapter->sessionStore()->create();
        $previous->addMessage(new UserMessage('Keep this conversation'));
        $adapter->agent()->setChatHistory($previous);
        $key = $adapter->sessionStore()->summaries()[0]->key;

        $execution = $commands->run('/clear', new CommandArguments(), $adapter);

        self::assertSame(['/clear', '/resume'], array_map(
            static fn (CommandInterface $command): string => $command->name(),
            $commands->all(),
        ));
        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertNotSame($previous, $adapter->agent()->getChatHistory());
        self::assertSame([], $adapter->agent()->getChatHistory()->getMessages());
        $reopened = $adapter->sessionStore()->read($key);
        self::assertNotNull($reopened);
        self::assertSame('Keep this conversation', $reopened->getMessages()[0]->getContent());
    }

    public function testArrayMountingPreservesOrderAndTheFirstDuplicateExecutes(): void
    {
        $first = new ClearCommand('/resume');
        $last = new ClearCommand('/last');
        $commands = new Commands([$first, new ClearCommand(), new ResumeCommand(), $last]);
        $adapter = new FakeCommandAdapter($commands);
        $previous = $adapter->sessionStore()->create();
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

    public function testInvalidArrayMembersAreRejectedAtMounting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Commands([new stdClass()]);
    }

    public function testResumeWithNoStoredSessionWarnsWithoutRequestingSelection(): void
    {
        $commands = new Commands([new ClearCommand(), new ResumeCommand()]);
        $adapter = new FakeCommandAdapter($commands);

        self::assertSame('completed', $commands->run('/resume', new CommandArguments(), $adapter)?->status);
        self::assertSame(['There is no earlier Session to return to yet.'], $adapter->warnings);
        self::assertSame([], $adapter->selections);
    }
}
