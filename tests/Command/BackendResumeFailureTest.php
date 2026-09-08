<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Examples\BackendAdapter;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackendResumeFailureTest extends TestCase
{
    #[DataProvider('preparationFailures')]
    public function testPreparationFailureLeavesPreviouslyStartedAgentUsable(string $failure, string $message): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'owner');
        $configurations = new ConfigurationStore($storage, 'owner');
        $agentFactoryRegistry = new AgentFactoryRegistry();
        $selected = $sessions->create();
        $selected->addMessage(new UserMessage('Saved conversation'));
        $original = $this->answeringAgent();
        $history = $sessions->create();
        $original->setChatHistory($history);
        $commands = new Commands(new ResumeCommand());
        $adapter = $this->adapter($original, $commands, $sessions, $agentFactoryRegistry, $configurations);
        $adapter->promptAgent('Initial turn');
        $thread = $original->getThreadId();

        if ($failure !== 'missing configuration') {
            $configurations->create('global', ['agent' => 'configured']);
        }
        if ($failure !== 'unknown factory') {
            $agentFactoryRegistry->register('configured', ResumeFailureAgent::class);
        }
        ResumeFailureAgent::$failure = $failure;
        ResumeFailureAgent::$constructions = 0;

        $response = $commands->run('/resume', new CommandArguments($selected->getKey()), $adapter);

        self::assertNotNull($response);
        self::assertSame('failed', $response['status']);
        self::assertSame($message, $response['error']);
        self::assertSame($original, $adapter->agent());
        self::assertSame($history, $adapter->agent()->getChatHistory());
        self::assertSame($thread, $adapter->agent()->getThreadId());
        $adapter->promptAgent('Turn after failed Resume');
        self::assertSame('Still answering', $history->getMessages()[3]->getContent());
        self::assertSame('Turn after failed Resume', $history->getMessages()[2]->getContent());
        self::assertCount(1, $sessions->read($selected->getKey())?->getMessages() ?? []);
        self::assertCount(2, $sessions->summaries());
    }

    /** @return Generator<string, array{string, string}> */
    public static function preparationFailures(): Generator
    {
        yield 'missing configuration' => ['missing configuration', 'Application configuration is missing.'];
        yield 'unknown factory' => ['unknown factory', 'Unknown Agent factory: configured'];
        yield 'factory exception' => ['factory', 'Factory dependency unavailable.'];
        yield 'History assignment exception' => ['history', 'History assignment failed.'];
    }

    #[DataProvider('inaccessibleSessions')]
    public function testMissingAndForeignSessionsWarnBeforeReadingConfigurationOrCallingFactory(bool $foreign, bool $configured): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'owner');
        $configurations = new ConfigurationStore($storage, 'owner');
        if ($configured) {
            $configurations->create('global', ['agent' => 'configured']);
        }
        $agentFactoryRegistry = new AgentFactoryRegistry();
        ResumeFailureAgent::$constructions = 0;
        ResumeFailureAgent::$failure = 'factory';
        $agentFactoryRegistry->register('configured', ResumeFailureAgent::class);
        $key = $foreign ? (new SessionStore($storage, 'someone-else'))->create()->getKey() : 'missing';
        $original = $this->answeringAgent();
        $commands = new Commands(new ResumeCommand());
        $adapter = $this->adapter($original, $commands, $sessions, $agentFactoryRegistry, $configurations);

        $response = $commands->run('/resume', new CommandArguments($key), $adapter);

        self::assertNotNull($response);
        self::assertSame('completed', $response['status']);
        self::assertNull($response['error']);
        self::assertSame(['No Session is named by that key.'], $response['warnings']);
        self::assertSame(0, ResumeFailureAgent::$constructions);
        self::assertSame($original, $adapter->agent());
    }

    /** @return Generator<string, array{bool, bool}> */
    public static function inaccessibleSessions(): Generator
    {
        yield 'missing without configuration' => [false, false];
        yield 'foreign without configuration' => [true, false];
        yield 'missing with failing factory' => [false, true];
        yield 'foreign with failing factory' => [true, true];
    }

    public function testSelectionNeedsNoConfigurationAndRechecksAvailabilityOnLaterRequest(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'owner');
        $selected = $sessions->create();
        $selected->addMessage(new UserMessage('Choice to remove'));
        $configurations = new ConfigurationStore($storage, 'owner');
        $agentFactoryRegistry = new AgentFactoryRegistry();
        ResumeFailureAgent::$constructions = 0;
        ResumeFailureAgent::$failure = 'factory';
        $agentFactoryRegistry->register('configured', ResumeFailureAgent::class);
        $original = $this->answeringAgent();
        $commands = new Commands(new ResumeCommand());
        $first = $commands->run('/resume', new CommandArguments(), $this->adapter(
            $original, $commands, $sessions, $agentFactoryRegistry, $configurations,
        ));

        self::assertNotNull($first);
        self::assertSame('completed', $first['status']);
        self::assertNotNull($first['selection']);
        self::assertCount(1, $first['selection']->options);
        self::assertSame($selected->getKey(), $first['selection']->options[0]->value);
        self::assertSame(0, ResumeFailureAgent::$constructions);

        $storage->delete('sessions', $selected->getKey());
        $configurations->create('global', ['agent' => 'configured']);
        $adapter = $this->adapter($original, $commands, $sessions, $agentFactoryRegistry, $configurations);
        $second = $commands->run($first['selection']->command, new CommandArguments($first['selection']->options[0]->value), $adapter);

        self::assertNotNull($second);
        self::assertSame('completed', $second['status']);
        self::assertSame(['No Session is named by that key.'], $second['warnings']);
        self::assertNull($second['selection']);
        self::assertSame(0, ResumeFailureAgent::$constructions);
        self::assertSame($original, $adapter->agent());
    }

    private function answeringAgent(): Agent
    {
        return new class extends Agent {
            protected function provider(): AIProviderInterface
            {
                return new FakeAIProvider(new AssistantMessage('Initial answer'), new AssistantMessage('Still answering'));
            }
        };
    }

    private function adapter(
        Agent $agent,
        Commands $commands,
        SessionStore $sessions,
        AgentFactoryRegistry $agentFactoryRegistry,
        ConfigurationStore $configurations,
    ): BackendAdapter {
        return new BackendAdapter($agent, $commands, $sessions, static function (Agent $answering, string $prompt): void {
            $answering->chat(new UserMessage($prompt));
        }, $agentFactoryRegistry, $configurations, 'configured');
    }
}

final class ResumeFailureAgent extends Agent implements \NeuronInteraction\Agent\ConfiguredAgentInterface
{
    public static string $failure = '';
    public static int $constructions = 0;

    public static function createAgent(ConfigurationStore $configurationStore): static
    {
        ++self::$constructions;
        if ($configurationStore->read('global') === null) {
            throw new RuntimeException('Application configuration is missing.');
        }
        if (self::$failure === 'factory') {
            throw new RuntimeException('Factory dependency unavailable.');
        }
        return new static();
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
        throw new RuntimeException('History assignment failed.');
    }
}
