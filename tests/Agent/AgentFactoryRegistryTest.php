<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Agent;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Agent\ConfiguredAgentInterface;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentFactoryRegistryTest extends TestCase
{
    public function testSelectedClassReadsApplicationDocumentsAndCreatesFreshAgents(): void
    {
        $registry = new AgentFactoryRegistry();
        $store = new ConfigurationStore(new InMemoryStorage(), 'owner');
        $store->create('preferences', ['language' => 'it']);
        $store->create('provider', ['model' => 'first']);
        $registry->register('chosen', RegistryAgent::class);
        $registry->register('other', BrokenRegistryAgent::class);

        $first = $registry->create('chosen', $store);
        self::assertInstanceOf(RegistryAgent::class, $first);
        self::assertSame($store, $first->store);
        self::assertSame('it:first', $first->settings);
        $configuration = $store->read('provider');
        self::assertNotNull($configuration);
        $configuration->set('model', 'second');
        $store->write($configuration);
        $second = $registry->create('chosen', $store);
        self::assertInstanceOf(RegistryAgent::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame('it:second', $second->settings);
    }

    public function testDuplicateDoesNotReplaceRegisteredClass(): void
    {
        $registry = new AgentFactoryRegistry();
        $registry->register('chosen', RegistryAgent::class);
        try {
            $registry->register('chosen', BrokenRegistryAgent::class);
            self::fail('Duplicate accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('already registered', $exception->getMessage());
        }
        self::assertInstanceOf(RegistryAgent::class, $registry->create('chosen', new ConfigurationStore(new InMemoryStorage(), 'owner')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidRegistrations(): iterable
    {
        yield 'empty identifier' => ['', RegistryAgent::class];
        yield 'blank identifier' => ['  ', RegistryAgent::class];
        yield 'missing class' => ['test', 'MissingAgentClass'];
        yield 'ordinary agent' => ['test', Agent::class];
        yield 'only interface' => ['test', OnlyConfigured::class];
        yield 'abstract agent' => ['test', AbstractConfiguredAgent::class];
    }

    #[DataProvider('invalidRegistrations')]
    public function testInvalidRegistrationFailsImmediately(string $identifier, string $agentClass): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Deliberately invalid caller exercises runtime validation.
        (new AgentFactoryRegistry())->register($identifier, $agentClass);
    }

    public function testUnknownIdentifierFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AgentFactoryRegistry())->create('unknown', new ConfigurationStore(new InMemoryStorage(), 'owner'));
    }

    public function testCreationExceptionPropagatesUnchanged(): void
    {
        $registry = new AgentFactoryRegistry();
        $registry->register('broken', BrokenRegistryAgent::class);
        $failure = new RuntimeException('Missing provider.');
        BrokenRegistryAgent::$failure = $failure;
        $this->expectExceptionObject($failure);
        $registry->create('broken', new ConfigurationStore(new InMemoryStorage(), 'owner'));
    }
}

final class RegistryAgent extends Agent implements ConfiguredAgentInterface
{
    public ConfigurationStore $store;
    public string $settings;

    public static function createAgent(ConfigurationStore $configurationStore): static
    {
        $agent = new static();
        $agent->store = $configurationStore;
        $language = $configurationStore->read('preferences')?->get('language', '');
        $model = $configurationStore->read('provider')?->get('model', '');
        $agent->settings = (is_string($language) ? $language : '') . ':' . (is_string($model) ? $model : '');
        return $agent;
    }
}

final class BrokenRegistryAgent extends Agent implements ConfiguredAgentInterface
{
    public static RuntimeException $failure;

    public static function createAgent(ConfigurationStore $configurationStore): static
    {
        throw self::$failure;
    }
}

final class OnlyConfigured implements ConfiguredAgentInterface
{
    public static function createAgent(ConfigurationStore $configurationStore): static
    {
        return new static();
    }
}

abstract class AbstractConfiguredAgent extends Agent implements ConfiguredAgentInterface {}
