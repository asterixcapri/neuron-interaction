<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use Generator;
use Closure;
use InvalidArgumentException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\Configuration;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandControlsAdapterInterface;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Examples\BackendAdapter;
use NeuronInteraction\Examples\ConfiguredAgent;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\FileStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackendExampleTest extends TestCase
{
    public function testClearAndDeferredResumeUseCurrentConfigurationAfterActualTurns(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'backend-user');
        $configurations = new ConfigurationStore($storage, 'backend-user');
        $configuration = $configurations->create('global', [
            'agent' => 'configured', 'model' => 'initial-model', 'capability' => 'search',
        ]);
        $factories = new AgentFactoryRegistry();
        $dependency = static fn (string $model, string $capability): AIProviderInterface =>
            new FakeAIProvider(new AssistantMessage($model . ':' . $capability));
        $factories->register('configured', static function (Configuration $configuration) use ($dependency): Agent {
            $model = $configuration->get('model');
            $capability = $configuration->get('capability');
            if (!is_string($model) || !is_string($capability)) {
                throw new InvalidArgumentException('Model and capability must be strings.');
            }
            $agent = new class($dependency) extends Agent {
                private string $modelId = '';
                private string $capability = '';

                /** @param Closure(string, string): AIProviderInterface $dependency */
                public function __construct(private readonly Closure $dependency)
                {
                    parent::__construct();
                }

                public function configure(string $model, string $capability): void
                {
                    $this->modelId = $model;
                    $this->capability = $capability;
                }

                protected function provider(): AIProviderInterface
                {
                    return ($this->dependency)($this->modelId, $this->capability);
                }
            };
            $agent->configure($model, $capability);

            return $agent;
        });
        $original = $factories->create($configuration);
        $previous = $sessions->create();
        $original->setChatHistory($previous);
        $commands = new Commands([new ClearCommand(), new ResumeCommand()]);
        $adapter = new BackendAdapter(
            $original, $commands, $sessions,
            static function (Agent $agent, string $prompt): void {
                $agent->chat(new UserMessage($prompt));
            },
            $factories, $configurations,
        );
        $adapter->promptAgent('First turn');
        self::assertSame('initial-model:search', $previous->getMessages()[1]->getContent());

        $configuration->set('model', 'current-model');
        $configuration->set('capability', 'research');
        $configurations->save($configuration);
        $response = $commands->run('/clear', new CommandArguments(), $adapter);

        self::assertNotNull($response);
        self::assertSame('completed', $response['status']);
        self::assertNotSame($original, $adapter->agent());
        self::assertNotSame($previous, $adapter->agent()->getChatHistory());
        self::assertNotSame($original->getThreadId(), $adapter->agent()->getThreadId());
        self::assertSame([], $adapter->agent()->getChatHistory()->getMessages());
        self::assertSame($factories, $adapter->agentFactoryRegistry());
        self::assertSame($configurations, $adapter->configurationStore());

        $adapter->promptAgent('Second turn');
        self::assertSame('current-model:research', $adapter->agent()->getChatHistory()->getMessages()[1]->getContent());
        self::assertSame('initial-model:search', $sessions->read($previous->getKey())?->getMessages()[1]->getContent());
        self::assertCount(2, $sessions->summaries());
        self::assertSame($configuration->all(), $configurations->read('global')?->all());

        $clearedAgent = $adapter->agent();
        $clearedHistory = $clearedAgent->getChatHistory();
        $selection = $commands->run('/resume', new CommandArguments(), $adapter);
        self::assertNotNull($selection);
        self::assertSame('completed', $selection['status']);
        self::assertSame($clearedAgent, $adapter->agent());
        $request = $selection['selection'];
        self::assertNotNull($request);
        $chosen = null;
        foreach ($request->options as $option) {
            if ($option->value === $previous->getKey()) {
                $chosen = $option->value;
            }
        }
        self::assertSame($previous->getKey(), $chosen);

        // Settings are saved while the client is presenting the selection.
        $configuration->set('model', 'latest-model');
        $configuration->set('capability', 'summarize');
        $configurations->save($configuration);
        $followUp = new BackendAdapter(
            $clearedAgent, $commands, $sessions,
            static function (Agent $agent, string $prompt): void {
                $agent->chat(new UserMessage($prompt));
            },
            $factories, $configurations,
        );
        $resumed = $commands->run($request->command, new CommandArguments($chosen), $followUp);
        self::assertNotNull($resumed);
        self::assertSame('completed', $resumed['status']);
        self::assertNotSame($original, $followUp->agent());
        self::assertNotSame($clearedAgent, $followUp->agent());
        self::assertSame($original->getThreadId(), $followUp->agent()->getThreadId());
        self::assertInstanceOf(\NeuronInteraction\Session\Session::class, $followUp->agent()->getChatHistory());
        self::assertSame($previous->getKey(), $followUp->agent()->getChatHistory()->getKey());
        $followUp->promptAgent('Third turn');
        self::assertSame('latest-model:summarize', $followUp->agent()->getChatHistory()->getMessages()[3]->getContent());
        self::assertSame('current-model:research', $clearedHistory->getMessages()[1]->getContent());
        self::assertCount(2, $sessions->summaries());
        self::assertSame($configuration->all(), $configurations->read('global')?->all());

    }

    public function testBackendReopensSavedConfigurationAndSessionsForClearAndResume(): void
    {
        $directory = sys_get_temp_dir() . '/neuron-backend-' . bin2hex(random_bytes(8));

        try {
            $storage = new FileStorage($directory);
            $savedConfigurations = new ConfigurationStore($storage, 'backend-user');
            $savedConfigurations->create('global', [
                'agent' => 'configured', 'model' => 'saved-model', 'capability' => 'research',
            ]);
            $savedSession = (new SessionStore($storage, 'backend-user'))->create();
            $savedSession->addMessage(new UserMessage('Earlier conversation'));
            $savedSession->addMessage(new AssistantMessage('Earlier answer'));

            $reopenedStorage = new FileStorage($directory);
            $configurations = new ConfigurationStore($reopenedStorage, 'backend-user');
            $sessions = new SessionStore($reopenedStorage, 'backend-user');
            $configuration = $configurations->read('global');
            $session = $sessions->read($savedSession->getKey());
            self::assertNotNull($configuration);
            self::assertNotNull($session);
            $factories = new AgentFactoryRegistry();
            $providerFactory = static fn (string $model, string $capability): AIProviderInterface =>
                new FakeAIProvider(new AssistantMessage($model . ':' . $capability));
            $factories->register('configured', static function (Configuration $configuration) use ($providerFactory): Agent {
                $model = $configuration->get('model');
                $capability = $configuration->get('capability');
                if (!is_string($model) || !is_string($capability)) {
                    throw new InvalidArgumentException('Model and capability must be strings.');
                }

                return (new ConfiguredAgent($providerFactory))->configure($model, $capability);
            });
            $initial = $factories->create($configuration);
            $initial->setChatHistory($session);
            $commands = new Commands([new ClearCommand(), new ResumeCommand()]);
            $adapter = new BackendAdapter(
                $initial, $commands, $sessions,
                static function (Agent $agent, string $prompt): void {
                    $agent->chat(new UserMessage($prompt));
                },
                $factories, $configurations,
            );
            $adapter->promptAgent('First turn after restart');
            self::assertSame('saved-model:research', $session->getMessages()[3]->getContent());

            $cleared = $commands->run('/clear', new CommandArguments(), $adapter);
            self::assertNotNull($cleared);
            self::assertSame('completed', $cleared['status']);
            self::assertNotSame($initial, $adapter->agent());
            self::assertNotSame($initial->getThreadId(), $adapter->agent()->getThreadId());
            $adapter->promptAgent('New conversation');
            self::assertSame('saved-model:research', $adapter->agent()->getChatHistory()->getMessages()[1]->getContent());

            $resumed = $commands->run('/resume', new CommandArguments($session->getKey()), $adapter);
            self::assertNotNull($resumed);
            self::assertSame('completed', $resumed['status']);
            self::assertSame($initial->getThreadId(), $adapter->agent()->getThreadId());
            $adapter->promptAgent('Continue earlier conversation');
            self::assertSame('saved-model:research', $adapter->agent()->getChatHistory()->getMessages()[5]->getContent());
            self::assertSame($configuration->all(), $savedConfigurations->read('global')?->all());
            self::assertSame('saved-model:research', $sessions->read($session->getKey())?->getMessages()[5]->getContent());
        } finally {
            foreach (glob($directory . '/*/*') ?: [] as $path) {
                unlink($path);
            }
            foreach (glob($directory . '/*') ?: [] as $path) {
                rmdir($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /** @param list<string> $expected */
    #[DataProvider('examples')]
    public function testBackendExampleRunsOnItsOwn(string $file, array $expected): void
    {
        ob_start();

        try {
            require dirname(__DIR__, 2) . '/examples/' . $file;
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertIsString($output);
        foreach ($expected as $text) {
            self::assertStringContainsString($text, $output);
        }
    }

    /** @return Generator<string, array{string, list<string>}> */
    public static function examples(): Generator
    {
        yield 'help' => ['help.php', ['"status": "completed"', 'Lists what can be typed here.']];
        yield 'exit' => ['exit.php', ['"status": "completed"', '"stopped": true']];
        yield 'clear' => ['clear.php', ['"currentMessages": []', '"storedConversations": 1']];
        yield 'resume by key' => ['resume-by-key.php', ['A conversation to reopen' . PHP_EOL]];
        yield 'resume selection' => ['resume-selection.php', [
            '"selection": {',
            '"label": "Planning a trip"',
            '"label": "Learning PHP"',
            '}' . PHP_EOL . 'Planning a trip' . PHP_EOL,
        ]];
    }

    public function testSelectionAndGeneratedPromptsCompleteAcrossFreshRequestsWithoutRecordingMoreInput(): void
    {
        $storage = new InMemoryStorage();
        $inputs = new InputHistory($storage);
        $inputs->record('/choose');
        $sessionStore = new SessionStore($storage, 'local-user');
        $command = new class implements CommandInterface {
            public function name(): string
            {
                return '/choose';
            }

            public function describe(): string
            {
                return 'Prompt the Agent with a chosen value.';
            }

            /** @param CommandControlsAdapterInterface<mixed> $adapter */
            public function run(CommandControlsAdapterInterface $adapter, CommandArguments $arguments): void
            {
                if ($arguments->text === '') {
                    $adapter->requestSelection(new SelectionRequest('/choose', 'Choose a value', [
                        new SelectionOption(" 007\n ", 'Visible label'),
                    ]));
                    $adapter->say('The selection request has returned.');

                    return;
                }

                $adapter->promptAgent($arguments->text);
                $adapter->warn('A response may still be pending.');
            }
        };
        $commands = new Commands($command);
        $received = [];
        $submitPrompt = static function (Agent $answering, string $prompt) use (&$received): void {
            $received[] = [$answering, $prompt];
        };
        $first = $commands->run('/choose', new CommandArguments(), new BackendAdapter(
            new Agent(), $commands, $sessionStore, $submitPrompt,
        ));

        self::assertNotNull($first);
        self::assertSame('completed', $first['status']);
        self::assertSame(['The selection request has returned.'], $first['notices']);
        self::assertSame([], $received); // Cancelling here requires no other invocation.
        self::assertEquals([
            'command' => '/choose',
            'prompt' => 'Choose a value',
            'options' => [['value' => " 007\n ", 'label' => 'Visible label', 'description' => null]],
            'description' => null,
        ], json_decode(json_encode($first['selection'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        self::assertNotNull($first['selection']);
        $selection = $first['selection'];
        $secondAgent = new Agent();
        $second = $commands->run($selection->command, new CommandArguments($selection->options[0]->value), new BackendAdapter(
            $secondAgent, $commands, $sessionStore, $submitPrompt,
        ));

        self::assertNotNull($second);
        self::assertSame('completed', $second['status']);
        self::assertNull($second['selection']);
        self::assertSame([], $second['notices']);
        self::assertSame(['A response may still be pending.'], $second['warnings']);
        self::assertSame([[$secondAgent, " 007\n "]], $received);
        self::assertSame(['/choose'], $inputs->entries());
    }

    public function testAgentReplacementUsesItsOwnHistoryAndImmediatelyHandlesFurtherEffects(): void
    {
        $storage = new InMemoryStorage();
        $sessionStore = new SessionStore($storage, 'local-user');
        $original = new Agent();
        $history = $original->getChatHistory();
        $history->addMessage(new UserMessage('Original conversation'));
        $replacement = new Agent();
        $command = new class($replacement) implements CommandInterface {
            public function __construct(private Agent $replacement)
            {
            }

            public function name(): string
            {
                return '/replace';
            }

            public function describe(): string
            {
                return 'Replace the Agent and choose another History.';
            }

            /** @param CommandControlsAdapterInterface<mixed> $adapter */
            public function run(CommandControlsAdapterInterface $adapter, CommandArguments $arguments): void
            {
                $session = $adapter->sessionStore()->create();
                $this->replacement->setChatHistory($session);
                $adapter->useAgent($this->replacement);
                TestCase::assertSame($this->replacement, $adapter->agent());
                TestCase::assertSame($session, $adapter->agent()->getChatHistory());
                $adapter->promptAgent('A generated prompt for the replacement.');
                $adapter->say($adapter->commands()->all()[0]->name());
                throw new RuntimeException('Failed after replacement.');
            }
        };
        $commands = new Commands($command);
        $received = [];
        $adapter = new BackendAdapter($original, $commands, $sessionStore, static function (Agent $answering, string $prompt) use (&$received): void {
            $received[] = [$answering, $prompt];
        });
        $response = $commands->run('/replace', new CommandArguments(), $adapter);

        self::assertNotNull($response);
        self::assertSame('failed', $response['status']);
        self::assertSame('Failed after replacement.', $response['error']);
        self::assertSame(['/replace'], $response['notices']);
        self::assertSame($replacement, $adapter->agent());
        self::assertNotSame($history, $replacement->getChatHistory());
        self::assertSame([], $replacement->getChatHistory()->getMessages());
        self::assertSame('Original conversation', $history->getMessages()[0]->getContent());
        self::assertSame($commands, $adapter->commands());
        self::assertSame($sessionStore, $adapter->sessionStore());
        self::assertSame([[$replacement, 'A generated prompt for the replacement.']], $received);
    }

    public function testBackendReturnsHelpLeaveAndUnknownResponsesFromRunAlone(): void
    {
        $commands = new Commands([new HelpCommand('/guide'), new LeaveCommand('/quit')]);
        $sessionStore = new SessionStore(new InMemoryStorage(), 'local-user');
        $responses = [];

        foreach (['/guide', '/missing', '/quit'] as $identifier) {
            $response = $commands->run($identifier, new CommandArguments(), new BackendAdapter(
                new Agent(), $commands, $sessionStore, static function (): void {},
            ));
            self::assertNotNull($response);
            $responses[$identifier] = $response;
        }

        self::assertSame('completed', $responses['/guide']['status']);
        self::assertSame([
            '/guide — Lists what can be typed here.',
            '/quit — Stops the interaction.',
        ], $responses['/guide']['notices']);
        self::assertFalse($responses['/guide']['stopped']);
        self::assertSame('unknown', $responses['/missing']['status']);
        self::assertSame('/missing', $responses['/missing']['identifier']);
        self::assertSame('completed', $responses['/quit']['status']);
        self::assertSame([], $responses['/quit']['notices']);
        self::assertTrue($responses['/quit']['stopped']);
    }
}
