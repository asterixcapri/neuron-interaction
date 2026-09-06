<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Examples\BackendAdapter;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackendExampleTest extends TestCase
{
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

            /** @param CommandAdapterInterface<mixed> $adapter */
            public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
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

    public function testAgentReplacementTransfersHistoryAndImmediatelyUsesTheReplacementForFurtherEffects(): void
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

            /** @param CommandAdapterInterface<mixed> $adapter */
            public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
            {
                $previous = $adapter->agent()->getChatHistory();
                $adapter->useAgent($this->replacement);
                TestCase::assertSame($this->replacement, $adapter->agent());
                TestCase::assertSame($previous, $adapter->agent()->getChatHistory());
                $adapter->useSession($adapter->sessionStore()->create());
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
