<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function iterator_to_array;

final class CommandInputTest extends TestCase
{
    public function testStringsDispatchLazilyAndPreserveOpaqueArguments(): void
    {
        $command = $this->command();
        $conversation = $this->conversation(new Commands($command));
        $stream = $conversation->sendInput('/echo  /help ');
        self::assertSame([], $command->values);
        $events = iterator_to_array($stream);
        self::assertSame([' /help '], $command->values);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame(' /help ', $events[0]->text);
        self::assertSame(NotificationLevel::Info, $events[0]->level);
        self::assertInstanceOf(Notification::class, $events[1]);
        self::assertSame(NotificationLevel::Warning, $events[1]->level);
        self::assertNull($stream->getReturn());
        iterator_to_array($conversation->sendInput(new CommandInput('/echo', '/help')));
        self::assertSame([' /help ', '/help'], $command->values);
    }

    public function testExplicitUserMessageIsNeverParsedAndBlankStringsDoNothing(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Literal slash'));
        $conversation = new Conversation((new Agent())->setAiProvider($provider), (new SessionStore(new InMemoryStorage(), 'owner'))->create());
        $blank = $conversation->sendInput('   ');
        self::assertSame([], iterator_to_array($blank));
        self::assertNull($blank->getReturn());
        $before = $provider->getRecorded();
        self::assertSame([], $before);
        iterator_to_array($conversation->sendInput(new UserMessage('/echo')));
        $recorded = $provider->getRecorded();
        self::assertSame('/echo', $recorded[0]->messages[0]->getContent());
    }

    public function testUnknownCommandIsAnErrorWithoutAgentExecution(): void
    {
        $events = iterator_to_array($this->conversation()->sendInput('/missing'));
        self::assertCount(1, $events);
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame(NotificationLevel::Error, $events[0]->level);
    }

    public function testRegisteredEffectsPrecedeOriginalFailureAndDoNotLeak(): void
    {
        $failure = new RuntimeException('Command failed');
        $command = new class ($failure) implements CommandInterface {
            public function __construct(private RuntimeException $failure) {}
            public function name(): string
            {
                return '/fail';
            }
            public function describe(): string
            {
                return 'Fail after effects';
            }
            public function run(CommandContext $context, string $value): void
            {
                $context->configurationStore()->write('changed', true);
                $context->notify($value);
                if ($value === 'fail') {
                    throw $this->failure;
                }
            }
        };
        $conversation = $this->conversation(new Commands($command));
        $stream = $conversation->sendInput('/fail fail');
        $stream->rewind();
        self::assertInstanceOf(Notification::class, $stream->current());
        self::assertSame('fail', $stream->current()->text);
        self::assertTrue($conversation->configurationStore()->read('changed', false));
        try {
            $stream->next();
            self::fail('Expected original failure');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertCount(1, iterator_to_array($conversation->sendInput('/fail success')));
    }

    public function testConfigurationDefaultsAreIsolatedAndSuppliedStoreIsReused(): void
    {
        $first = $this->conversation();
        $second = $this->conversation();
        $first->configurationStore()->write('key', 'first');
        self::assertNull($second->configurationStore()->read('key'));
        $store = new ConfigurationStore(new InMemoryStorage(), 'owner');
        $provided = new Conversation(new Agent(), (new SessionStore(new InMemoryStorage(), 'owner'))->create());
        $provided->setConfigurationStore($store);
        self::assertSame($store, $provided->configurationStore());
    }

    public function testRegistryPreservesOrderAndReplacesDuplicateNames(): void
    {
        $command = $this->command();
        $registry = new Commands($command);
        self::assertSame([$command], $registry->all());
        self::assertSame($command, $registry->named('/echo'));
        self::assertNull($registry->named('/absent'));
        self::assertSame([$command], (new Commands($command, $command))->all());
    }

    public function testInvalidIdentifierIsRejectedByRegistry(): void
    {
        $command = $this->command('not-a-command');
        $this->expectException(InvalidArgumentException::class);
        new Commands($command);
    }

    public function testParserDistinguishesSlashTextAndPreservesArguments(): void
    {
        foreach (['message', '/path/file', '/', ' /echo', '/echo!'] as $line) {
            self::assertNull(CommandInput::parse($line));
        }
        $input = CommandInput::parse('/echo\tvalue');
        self::assertNull($input);
        $input = CommandInput::parse("/echo\tvalue\nnext");
        self::assertNotNull($input);
        self::assertSame('/echo', $input->identifier);
        self::assertSame("value\nnext", $input->value);
    }

    public function testRegistryCanBeReplacedAndExtendedBeforeStreamConsumption(): void
    {
        $original = $this->command('/old');
        $replacement = $this->command();
        $conversation = $this->conversation(new Commands($original));
        $stream = $conversation->sendInput('/echo first');
        $commands = new Commands($replacement);
        $conversation->setCommands($commands);
        iterator_to_array($stream);

        self::assertSame([], $original->values);
        self::assertSame(['first'], $replacement->values);
        $events = iterator_to_array($conversation->sendInput('/old'));
        self::assertInstanceOf(Notification::class, $events[0]);
        self::assertSame(NotificationLevel::Error, $events[0]->level);

        $latest = $this->command();
        $stream = $conversation->sendInput('/echo second');
        $commands->addCommand($latest);
        iterator_to_array($stream);

        self::assertSame(['first'], $replacement->values);
        self::assertSame(['second'], $latest->values);
        self::assertSame($commands, $conversation->commands());
    }

    private function conversation(Commands $commands = new Commands()): Conversation
    {
        $conversation = new Conversation(new Agent(), (new SessionStore(new InMemoryStorage(), 'owner'))->create());
        $conversation->setCommands($commands);
        return $conversation;
    }

    private function command(string $name = '/echo'): EchoInputTestCommand
    {
        return new EchoInputTestCommand($name);
    }
}

final class EchoInputTestCommand implements CommandInterface
{
    /** @var list<string> */
    public array $values = [];

    public function __construct(private string $identifier) {}

    public function name(): string
    {
        return $this->identifier;
    }

    public function describe(): string
    {
        return 'Echo raw arguments';
    }

    public function run(CommandContext $context, string $value): void
    {
        $this->values[] = $value;
        $context->notify($value);
        $context->notify('Warning', NotificationLevel::Warning);
    }
}
