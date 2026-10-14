<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function iterator_to_array;

final class ConversationRecoveryTest extends TestCase
{
    /** @param list<Message> $saved */
    #[DataProvider('historyTails')]
    public function testRecoveryChangesOnlyAnUnansweredInputAndDoesNotDuplicateTheNote(array $saved, int $expectedCount): void
    {
        $failure = new RuntimeException('Response timed out');
        $provider = new class ($failure) extends FakeAIProvider {
            public function __construct(private RuntimeException $failure)
            {
                parent::__construct(new AssistantMessage('Unused'), new AssistantMessage('Unused'));
            }

            protected function streamChunks(Message $response): Generator
            {
                yield from [];
                throw $this->failure;
            }
        };
        $session = (new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'owner'))->create();
        $conversation = new Conversation((new Agent())->setAiProvider($provider), $session);
        foreach ($saved as $message) {
            $conversation->agent()->getChatHistory()->addMessage($message);
        }

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                iterator_to_array($conversation->sendInput('Continue'));
                self::fail('Expected the timeout.');
            } catch (RuntimeException $error) {
                self::assertSame($failure, $error);
            }
            self::assertCount($expectedCount, $session->getMessages());
            foreach ($saved as $index => $message) {
                self::assertSame($message->getId(), $session->getMessages()[$index]->getId());
            }
        }
    }

    /** @return iterable<string, array{list<Message>, int}> */
    public static function historyTails(): iterable
    {
        yield 'empty' => [[], 0];
        yield 'completed' => [[new UserMessage('Question'), new AssistantMessage('Answer')], 2];
        yield 'unanswered input' => [[new UserMessage('Question')], 2];
        yield 'unresolved tool' => [[
            new UserMessage('Question'),
            new ToolCallMessage(tools: [new ToolCall('lookup', 'pending-call')]),
        ], 2];
    }

    public function testTimeoutAfterCompletedToolAllowsAnotherMessageAndSessionReload(): void
    {
        $failure = new RuntimeException('Response timed out');
        $provider = new class ($failure) extends FakeAIProvider {
            public function __construct(private RuntimeException $failure)
            {
                parent::__construct(
                    new AssistantMessage('Timeout'),
                    new AssistantMessage('Recovered'),
                    new AssistantMessage('After reload'),
                );
            }

            protected function streamChunks(Message $response): Generator
            {
                if ($response->getContent() === 'Timeout') {
                    throw $this->failure;
                }
                return yield from parent::streamChunks($response);
            }
        };
        $store = new SessionStore(new InMemoryStorage(), new InMemoryMessageStore(), 'owner');
        $session = $store->create();
        $conversation = new Conversation((new Agent())->setAiProvider($provider), $session);
        // Replay the persisted tail of a session interrupted after a completed tool.
        $call = (new ToolCall('lookup', 'lookup-call'))->setResult('Saved result');
        $history = $conversation->agent()->getChatHistory();
        $history->addMessage(new UserMessage('Look it up'));
        $history->addMessage(new ToolCallMessage(tools: [$call]));
        $history->addMessage(new ToolResultMessage([$call]));

        try {
            iterator_to_array($conversation->sendInput('Look it up'));
            self::fail('Expected the timeout.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }

        $saved = $session->getMessages();
        self::assertCount(4, $saved);
        self::assertInstanceOf(ToolResultMessage::class, $saved[2]);
        self::assertSame('Saved result', $saved[2]->getToolCalls()[0]->getResult());
        self::assertInstanceOf(AssistantMessage::class, $saved[3]);
        self::assertSame('The previous response was interrupted by an error.', $saved[3]->getContent());

        iterator_to_array($conversation->sendInput('Continue'));
        self::assertSame('Recovered', $session->getMessages()[5]->getContent());

        $reloaded = $store->get($session->getKey());
        self::assertNotNull($reloaded);
        $conversation = new Conversation((new Agent())->setAiProvider($provider), $reloaded);
        iterator_to_array($conversation->sendInput('Another question'));
        self::assertCount(8, $reloaded->getMessages());
        self::assertSame('After reload', $reloaded->getMessages()[7]->getContent());
    }
}
