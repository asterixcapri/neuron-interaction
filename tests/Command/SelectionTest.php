<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\Selection;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class SelectionTest extends TestCase
{
    public function testSelectionSerializesOrderedOptionsAndReceivesTheValueInANewInvocation(): void
    {
        $request = new Selection('/choose', 'Pick a value', [
            new SelectionOption('007', 'Visible label', 'Detailed description'),
            new SelectionOption(' raw value ', 'Another label'),
        ]);
        $command = new class ($request) implements CommandInterface {
            public function __construct(private Selection $request) {}

            public function name(): string
            {
                return '/choose';
            }

            public function describe(): string
            {
                return 'Select a value.';
            }

            /** @param CommandAdapterInterface<mixed> $adapter */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                if ($value === '') {
                    $adapter->requestSelection($this->request);
                    $adapter->notify('First invocation finished.');

                    return;
                }

                $adapter->notify($value);
            }
        };
        $commands = (new Commands())->addCommand([$command]);
        $first = new FakeCommandAdapter($commands);

        self::assertSame('completed', $commands->run('/choose', '', $first)?->status);
        self::assertSame(['First invocation finished.'], $first->notices);
        self::assertSame([$request], $first->selections);
        self::assertEquals([
            'command' => '/choose',
            'prompt' => 'Pick a value',
            'options' => [
                ['value' => '007', 'label' => 'Visible label', 'description' => 'Detailed description'],
                ['value' => ' raw value ', 'label' => 'Another label', 'description' => null],
            ],
            'description' => null,
        ], json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

        // A later Adapter invocation has a fresh Adapter, without hidden selection state.
        $second = new FakeCommandAdapter($commands);
        $execution = $commands->run($request->command, $request->options[1]->value, $second);

        self::assertNotNull($execution);
        self::assertSame('completed', $execution->status);
        self::assertSame([' raw value '], $second->notices);
        self::assertSame([], $second->selections);
    }

    public function testResumeRequestsSelectionThenInstallsTheChosenHistoryOnlyOnTheSecondInvocation(): void
    {
        $commands = (new Commands())->addCommand([new ResumeCommand('/return')]);
        $adapter = new FakeCommandAdapter($commands);
        $stored = $adapter->sessionStore()->create();
        SessionHistory::of($stored)->addMessage(new UserMessage('Stored subject'));
        $active = $adapter->sessionStore()->create();
        $adapter->useSession($active);
        $session = $adapter->sessionStore()->summaries()[0];

        $first = $commands->run('/return', '', $adapter);

        self::assertNotNull($first);
        self::assertSame('completed', $first->status);
        self::assertSame($active->getKey(), $adapter->agent()->getChatHistory()->getThreadId());
        self::assertSame($active->getKey(), $adapter->session()->getKey());
        self::assertCount(1, $adapter->selections);
        $request = $adapter->selections[0];
        self::assertSame('/return', $request->command);
        self::assertSame($session->key, $request->options[0]->value);
        self::assertSame('New session', $request->options[0]->label);
        self::assertNotNull($request->options[0]->description);

        $second = $commands->run('/return', $request->options[0]->value, $adapter);

        self::assertNotNull($second);
        self::assertSame('completed', $second->status);
        self::assertSame('Stored subject', $adapter->agent()->getChatHistory()->getMessages()[0]->getContent());
        self::assertSame($stored->getKey(), $adapter->session()->getKey());
        self::assertCount(1, $adapter->selections);
    }

    public function testResumeWithAKeyNeedsNoPriorSelectionAndUnknownKeysFailNormally(): void
    {
        $commands = (new Commands())->addCommand([new ResumeCommand()]);
        $adapter = new FakeCommandAdapter($commands);
        SessionHistory::of($adapter->sessionStore()->create())->addMessage(new UserMessage('Direct resume'));
        $key = $adapter->sessionStore()->summaries()[0]->key;

        self::assertSame('completed', $commands->run('/resume', $key, $adapter)?->status);
        self::assertSame('Direct resume', $adapter->agent()->getChatHistory()->getMessages()[0]->getContent());
        self::assertSame([], $adapter->selections);

        $history = $adapter->agent()->getChatHistory();
        self::assertSame('completed', $commands->run('/resume', 'unknown', $adapter)?->status);
        self::assertSame($history->getThreadId(), $adapter->agent()->getChatHistory()->getThreadId());
        self::assertSame(['No Session is named by that key.'], $adapter->errors);
        self::assertSame([], $adapter->warnings);
    }
}
