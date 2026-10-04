<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class SelectionRequestTest extends TestCase
{
    public function testSeparateHttpRequestsRestoreOnlySessionAndStoresAndForwardOpaqueValues(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'owner');
        $configuration = new ConfigurationStore($storage, 'owner');
        $configuration->write('account', 'original owner');
        $first = new Conversation(new Agent(), $sessions->create(), configurationStore: $configuration);
        $first->setCommands(new Commands(new HttpSelectionCommand()));
        $first->session()->setTitle('Original Session');
        $events = iterator_to_array($first->sendInput('/choose'));
        self::assertCount(1, $events);
        self::assertInstanceOf(SelectionRequest::class, $events[0]);
        $request = $events[0];
        self::assertEquals([
            'command' => '/choose',
            'prompt' => 'Choose a value',
            'options' => [
                ['value' => '007', 'label' => 'Seven', 'description' => 'An opaque identifier'],
                ['value' => ' raw value ', 'label' => 'Whitespace', 'description' => null],
            ],
            'description' => 'The value is sent back as-is.',
        ], json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $sessionKey = $first->session()->getKey();
        $identifier = $request->command;
        unset($request, $events, $first, $sessions, $configuration);

        // A new HTTP request needs no SelectionRequest or previous Command instance.
        $secondStore = new SessionStore($storage, 'owner');
        $session = $secondStore->get($sessionKey);
        self::assertNotNull($session);
        $second = new Conversation(
            new Agent(),
            $session,
            configurationStore: new ConfigurationStore($storage, 'owner'),
        );
        $second->setCommands(new Commands(new HttpSelectionCommand()));
        foreach ([' raw value ', '/help/not-an-option'] as $value) {
            $stream = $second->sendInput(new CommandInput($identifier, $value));
            $events = iterator_to_array($stream);
            self::assertCount(1, $events);
            self::assertInstanceOf(Notification::class, $events[0]);
            self::assertSame($value, $events[0]->text);
            self::assertNull($stream->getReturn());
            self::assertSame($value, $second->configurationStore()->read('chosen'));
            self::assertSame($value, $secondStore->get($sessionKey)?->getMetadata()['chosen']);
            self::assertSame($sessionKey, $second->agent()->getThreadId());
        }
    }

    public function testMultipleSelectionsAreEmittedInOrderAndNextInvocationCanRequestAnotherStep(): void
    {
        $first = new SelectionRequest('/steps', 'First', [new SelectionOption('second', 'Next')]);
        $second = new SelectionRequest('/steps', 'Second', [new SelectionOption('finish', 'Finish')]);
        $command = new class ($first, $second) implements CommandInterface {
            public function __construct(private SelectionRequest $first, private SelectionRequest $second) {}

            public function name(): string
            {
                return '/steps';
            }

            public function describe(): string
            {
                return 'A multi-step choice';
            }

            public function run(CommandContext $context, string $value): void
            {
                if ($value === '') {
                    $context->requestSelection($this->first);
                    $context->notify('Between selections');
                    $context->requestSelection($this->second);
                } elseif ($value === 'second') {
                    $context->requestSelection($this->second);
                } else {
                    $context->notify($value);
                }
            }
        };
        $conversation = new Conversation(new Agent(), (new SessionStore(new InMemoryStorage(), 'owner'))->create());
        $conversation->setCommands(new Commands($command));
        $events = iterator_to_array($conversation->sendInput('/steps'));
        self::assertCount(3, $events);
        self::assertSame($first, $events[0]);
        self::assertInstanceOf(Notification::class, $events[1]);
        self::assertSame('Between selections', $events[1]->text);
        self::assertSame($second, $events[2]);
        self::assertSame([$second], iterator_to_array($conversation->sendInput(new CommandInput('/steps', 'second'))));
        $events = iterator_to_array($conversation->sendInput(new CommandInput('/steps', 'finish')));
        self::assertCount(1, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame('finish', $events[0]->text);
        self::assertSame([$second], iterator_to_array($conversation->sendInput(new CommandInput('/steps', 'second'))));
    }

    public function testEmptyOptionsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SelectionRequest('/choose', 'Choose', []);
    }

    public function testOptionsMustBeAnOrderedList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SelectionRequest('/choose', 'Choose', [1 => new SelectionOption('a', 'A')]);
    }
}

final class HttpSelectionCommand implements CommandInterface
{
    public function name(): string
    {
        return '/choose';
    }

    public function describe(): string
    {
        return 'Choose an opaque value';
    }

    public function run(CommandContext $context, string $value): void
    {
        if ($value === '') {
            $context->requestSelection(new SelectionRequest($this->name(), 'Choose a value', [
                new SelectionOption('007', 'Seven', 'An opaque identifier'),
                new SelectionOption(' raw value ', 'Whitespace'),
            ], 'The value is sent back as-is.'));
            return;
        }

        if ($context->configurationStore()->read('account') !== 'original owner' || $context->session()->getTitle() !== 'Original Session') {
            throw new InvalidArgumentException('The HTTP request did not restore the original context.');
        }
        $context->configurationStore()->write('chosen', $value);
        $context->session()->setMetadata('chosen', $value);
        $context->notify($value);
    }
}
