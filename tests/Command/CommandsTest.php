<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use Closure;
use InvalidArgumentException;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandExecution;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CommandsTest extends TestCase
{
    public function testDispatchPreservesRawArgumentsAndMountingOrderWithFirstDuplicateWinning(): void
    {
        $received = null;
        $first = self::command('/review', static function (CommandArguments $arguments) use (&$received): void {
            $received = $arguments;
        });
        $duplicate = self::command('/review', static function (): void {
            self::fail('The duplicate must not execute.');
        });
        $last = self::command('/last', static function (): void {
        });
        $commands = new Commands([$first, $duplicate, $last]);
        $arguments = new CommandArguments(" \tline one\n  line two \t");

        $execution = $commands->run('/review', $arguments, new FakeCommandAdapter());

        self::assertSame([$first, $duplicate, $last], $commands->all());
        self::assertSame($first, $commands->named('/review'));
        self::assertSame($arguments, $received);
        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame('/review', $execution->identifier);
        self::assertNull($execution->exception);
    }

    public function testLookupIsExactWithoutPrefixOrCaseNormalization(): void
    {
        $commands = new Commands([self::command('/review', static function (): void {
            self::fail('An unknown identifier must not execute.');
        })]);

        foreach (['/missing', 'review', '/Review'] as $identifier) {
            $execution = $commands->run($identifier, new CommandArguments(), new FakeCommandAdapter());
            self::assertNotNull($execution);
            self::assertSame('unknown', $execution->status);
            self::assertSame($identifier, $execution->identifier);
            self::assertNull($execution->exception);
        }
    }

    public function testFailureKeepsOriginalExceptionAndDispatcherRemainsUsable(): void
    {
        $failure = new RuntimeException('Command failed');
        $commands = new Commands([
            self::command('/broken', static function () use ($failure): void {
                throw $failure;
            }),
            self::command('/healthy', static function (): void {
            }),
        ]);
        $adapter = new FakeCommandAdapter();
        $execution = $commands->run('/broken', new CommandArguments(), $adapter);

        self::assertNotNull($execution);
        self::assertSame('failed', $execution->status);
        self::assertSame('/broken', $execution->identifier);
        self::assertSame($failure, $execution->exception);
        self::assertSame('completed', $commands->run('/healthy', new CommandArguments(), $adapter)?->status);
    }

    public function testUnknownIdentifierCompletesWithoutAdmissionAndReturnsTheAdaptersOutputUnchanged(): void
    {
        $output = CommandExecution::completed('/adapter-output');
        $adapter = new class($output) extends FakeCommandAdapter {
            public function __construct(private CommandExecution $output)
            {
                parent::__construct();
            }

            public function admit(CommandInterface $command): bool
            {
                throw new RuntimeException('Unknown identifiers cannot request admission.');
            }

            public function afterExecution(CommandExecution $execution): CommandExecution
            {
                TestCase::assertSame('unknown', $execution->status);
                TestCase::assertSame('/missing', $execution->identifier);
                TestCase::assertNull($execution->exception);

                return $this->output;
            }
        };

        self::assertSame($output, (new Commands())->run('/missing', new CommandArguments(), $adapter));
    }

    public function testRefusalUsesTheFirstMatchingCommandWithoutDispatchOrCompletion(): void
    {
        $first = self::command('/duplicate', static function (): void {
            self::fail('The refused Command must not execute.');
        });
        $duplicate = self::command('/duplicate', static function (): void {
            self::fail('Admission must not fall through to a duplicate.');
        });
        $adapter = new class($first) extends FakeCommandAdapter {
            public function __construct(private CommandInterface $refused)
            {
                parent::__construct();
            }

            public function admit(CommandInterface $command): bool
            {
                if ($command === $this->refused) {
                    $this->warn('Refused by this Adapter.');

                    return false;
                }

                return true;
            }

            public function afterExecution(CommandExecution $execution): CommandExecution
            {
                throw new RuntimeException('Refusal must not complete an invocation.');
            }
        };

        self::assertNull((new Commands([$first, $duplicate]))->run('/duplicate', new CommandArguments(), $adapter));
        self::assertSame(['Refused by this Adapter.'], $adapter->warnings);
        self::assertSame([], $adapter->notices);
    }

    public function testCompletionReceivesTheOutcomeAfterCommandEffectsAndReturnsItsOwnOutput(): void
    {
        $failure = new RuntimeException('Command failed after its notice.');
        $output = CommandExecution::completed('/adapter-output');
        $commands = new Commands([
            self::command('/healthy', static function (CommandArguments $arguments, CommandAdapterInterface $adapter): void {
                $adapter->say($arguments->text);
            }),
            self::command('/broken', static function (CommandArguments $arguments, CommandAdapterInterface $adapter) use ($failure): void {
                $adapter->say($arguments->text);
                throw $failure;
            }),
        ]);

        foreach (['/healthy' => null, '/broken' => $failure] as $identifier => $exception) {
            $adapter = new class($identifier, $exception, $output) extends FakeCommandAdapter {
                public function __construct(
                    private string $identifier,
                    private ?RuntimeException $exception,
                    private CommandExecution $output,
                ) {
                    parent::__construct();
                }

                public function afterExecution(CommandExecution $execution): CommandExecution
                {
                    TestCase::assertSame(['Effect survives completion.'], $this->notices);
                    TestCase::assertSame($this->identifier, $execution->identifier);
                    TestCase::assertSame($this->exception === null ? 'completed' : 'failed', $execution->status);
                    TestCase::assertSame($this->exception, $execution->exception);

                    return $this->output;
                }
            };

            self::assertSame($output, $commands->run($identifier, new CommandArguments('Effect survives completion.'), $adapter));
        }
    }

    public function testAdmissionExceptionsPropagateWithoutInvokingOrCompletingTheCommand(): void
    {
        $failure = new RuntimeException('Admission failed.');
        $adapter = new class($failure) extends FakeCommandAdapter {
            public function __construct(private RuntimeException $failure)
            {
                parent::__construct();
            }

            public function admit(CommandInterface $command): bool
            {
                throw $this->failure;
            }

            public function afterExecution(CommandExecution $execution): CommandExecution
            {
                throw new RuntimeException('Admission failures do not complete.');
            }
        };
        $commands = new Commands(self::command('/review', static function (): void {
            self::fail('The Command cannot run after an admission failure.');
        }));

        try {
            $commands->run('/review', new CommandArguments(), $adapter);
            self::fail('The admission failure must reach the caller.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testCompletionExceptionsPropagateWithoutBeingRetriedAsCommandFailures(): void
    {
        $failure = new RuntimeException('Completion failed.');
        $commands = new Commands([
            self::command('/healthy', static function (): void {}),
            self::command('/broken', static function (): void {
                throw new RuntimeException('A Command failure.');
            }),
        ]);

        foreach (['/healthy', '/broken', '/unknown'] as $identifier) {
            $adapter = new class($failure) extends FakeCommandAdapter {
                public function __construct(private RuntimeException $failure)
                {
                    parent::__construct();
                }

                public function afterExecution(CommandExecution $execution): CommandExecution
                {
                    if ($execution->exception === $this->failure) {
                        throw new RuntimeException('Completion was retried as a Command failure.');
                    }

                    throw $this->failure;
                }
            };

            try {
                $commands->run($identifier, new CommandArguments(), $adapter);
                self::fail('The completion failure must reach the caller.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
        }
    }

    public function testSlashlessIdentifiersAreRejectedInEveryConstructorMountingForm(): void
    {
        foreach (['review', ''] as $name) {
            $command = self::command($name, static function (): void {});
            foreach ([$command, [$command], [new ObjectKit([$command])]] as $mount) {
                try {
                    new Commands($mount);
                    self::fail('A slashless identifier must fail at mounting.');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('slash', $exception->getMessage());
                }
            }
        }
    }

    public function testSlashRequirementDoesNotIntroduceAdditionalIdentifierGrammar(): void
    {
        foreach (['/', '//review', '/Review', '/two words'] as $name) {
            $command = self::command($name, static function (): void {});
            self::assertSame($command, (new Commands($command))->named($name));
        }
    }

    /** @param Closure(CommandArguments, CommandAdapterInterface<mixed>): void $run */
    private static function command(string $name, Closure $run): CommandInterface
    {
        return new class($name, $run) implements CommandInterface {
            /** @param Closure(CommandArguments, CommandAdapterInterface<mixed>): void $run */
            public function __construct(private string $name, private Closure $run)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function describe(): string
            {
                return 'A test Command';
            }

            /** @param CommandAdapterInterface<mixed> $adapter */
            public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
            {
                ($this->run)($arguments, $adapter);
            }
        };
    }
}
