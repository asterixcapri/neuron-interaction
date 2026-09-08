<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClearCommandTest extends TestCase
{
    public function testConstructionFailureDoesNotCreateSession(): void
    {
        $storage = new InMemoryStorage();
        $commands = new Commands(new ClearCommand());
        $adapter = new FakeCommandAdapter($commands, collection: new SessionStore($storage, 'owner'));
        $adapter->factory = static fn (): Agent => throw new RuntimeException('Missing application settings.');
        $original = $adapter->agent();

        $execution = $commands->run('/clear', new CommandArguments(), $adapter);

        self::assertSame('failed', $execution?->status);
        self::assertSame('Missing application settings.', $execution->exception?->getMessage());
        self::assertSame($original, $adapter->agent());
        self::assertSame([], iterator_to_array($storage->entries('sessions')));
    }

    public function testFactoryFailurePreservesExceptionAndDoesNotCreateSession(): void
    {
        $storage = new InMemoryStorage();
        $commands = new Commands(new ClearCommand());
        $adapter = new FakeCommandAdapter($commands, collection: new SessionStore($storage, 'owner'));
        $adapter->configurationStore()->create('global', ['agent' => 'broken']);
        $failure = new RuntimeException('Required application dependency unavailable.');
        $adapter->factory = static function () use ($failure): Agent {
            throw $failure;
        };
        $original = $adapter->agent();

        $execution = $commands->run('/clear', new CommandArguments(), $adapter);

        self::assertSame('failed', $execution?->status);
        self::assertSame($failure, $execution->exception);
        self::assertSame($original, $adapter->agent());
        self::assertSame([], iterator_to_array($storage->entries('sessions')));
    }

    public function testHistoryFailureLeavesOldAgentActiveAndCreatedSessionStored(): void
    {
        $storage = new InMemoryStorage();
        $commands = new Commands(new ClearCommand());
        $adapter = new FakeCommandAdapter($commands, collection: new SessionStore($storage, 'owner'));
        $adapter->configurationStore()->create('global', ['agent' => 'broken']);
        $adapter->factory = static fn (): Agent => new class extends Agent {
            public function setChatHistory(ChatHistoryInterface $chatHistory): self
            {
                throw new RuntimeException('History assignment failed.');
            }
        };
        $original = $adapter->agent();

        $execution = $commands->run('/clear', new CommandArguments(), $adapter);

        self::assertSame('failed', $execution?->status);
        self::assertSame('History assignment failed.', $execution->exception?->getMessage());
        self::assertSame($original, $adapter->agent());
        self::assertCount(1, iterator_to_array($storage->entries('sessions')));
    }
}
