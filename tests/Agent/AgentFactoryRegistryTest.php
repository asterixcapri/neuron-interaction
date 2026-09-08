<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Agent;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

final class AgentFactoryRegistryTest extends TestCase
{
    public function testSelectsExactlyTheRegisteredFactoryAndDetachesConfiguration(): void
    {
        $registry = new AgentFactoryRegistry();
        $configuration = new Configuration('global', 'owner', ['agent' => 'chosen', 'model' => 'first']);
        $registry->register('other', static function (): Agent {
            throw new RuntimeException('Wrong factory');
        });
        $registry->register('chosen', static function (Configuration $copy) use ($configuration): Agent {
            self::assertNotSame($configuration, $copy);
            self::assertSame('global', $copy->getKey());
            self::assertSame('owner', $copy->getUserId());
            $copy->set('model', 'changed');
            return new Agent();
        });

        self::assertNotSame($registry->create($configuration), $registry->create($configuration));
        self::assertSame('first', $configuration->get('model'));
    }

    public function testRejectsDuplicateRegistrationWithoutReplacingFactory(): void
    {
        $registry = new AgentFactoryRegistry();
        $agent = new Agent();
        $registry->register('chosen', static fn (): Agent => $agent);
        try {
            $registry->register('chosen', static fn (): Agent => new Agent());
            self::fail('Duplicate registration accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('already registered', $exception->getMessage());
        }
        self::assertSame($agent, $registry->create(new Configuration('global', 'owner', ['agent' => 'chosen'])));
    }

    #[DataProvider('emptyIdentifiers')]
    public function testRejectsEmptyRegistration(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AgentFactoryRegistry())->register($identifier, static fn (): Agent => new Agent());
    }

    /** @return iterable<string, array{string}> */
    public static function emptyIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['  '];
    }

    #[DataProvider('invalidConfigurations')]
    public function testRejectsMissingInvalidAndUnknownSelection(Configuration $configuration): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AgentFactoryRegistry())->create($configuration);
    }

    /** @return iterable<string, array{Configuration}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'missing' => [new Configuration('global', 'owner')];
        foreach ([null, '', '  ', 42, false, [], 'unknown', Agent::class] as $index => $identifier) {
            yield 'value ' . $index => [new Configuration('global', 'owner', ['agent' => $identifier])];
        }
    }

    public function testPreservesFactoryException(): void
    {
        $failure = new RuntimeException('Application configuration is invalid.');
        $registry = new AgentFactoryRegistry();
        $registry->register('broken', static function () use ($failure): Agent {
            throw $failure;
        });
        $this->expectExceptionObject($failure);
        $registry->create(new Configuration('global', 'owner', ['agent' => 'broken']));
    }

    public function testEnforcesAgentReturnContract(): void
    {
        $registry = new AgentFactoryRegistry();
        // Deliberately invalid application code exercises the runtime contract.
        // @phpstan-ignore argument.type
        $registry->register('broken', static fn (): string => 'not an Agent');
        $this->expectException(TypeError::class);
        $registry->create(new Configuration('global', 'owner', ['agent' => 'broken']));
    }
}
