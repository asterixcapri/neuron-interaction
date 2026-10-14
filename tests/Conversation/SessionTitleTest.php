<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Event\SessionTitleChanged;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Session\SessionTitleGenerator;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_values;
use function iterator_to_array;

final class SessionTitleTest extends TestCase
{
    public function testGenerationRequiresConfigurationAndPreservesTheCompletedTurn(): void
    {
        $conversation = $this->conversation('First answer', 'Second answer', 'Third answer');
        self::assertNull($conversation->sessionTitleGenerator());
        iterator_to_array($conversation->sendInput('Configure Redis'));
        self::assertNull($conversation->session()->getTitle());
        self::assertArrayNotHasKey('titleGenerationAttempts', $conversation->session()->getMetadata());

        $provider = new FakeAIProvider(new AssistantMessage('{"title":"Redis setup"}'));
        $conversation->setSessionTitleGenerator(new SessionTitleGenerator($provider));
        self::assertInstanceOf(SessionTitleGenerator::class, $conversation->sessionTitleGenerator());
        $stream = $conversation->sendInput('Continue');
        $events = iterator_to_array($stream, false);
        self::assertInstanceOf(AgentState::class, $stream->getReturn());
        self::assertSame('Second answer', $stream->getReturn()->getMessage()?->getContent());
        $changed = $this->titleEvents($events);
        self::assertCount(1, $changed);
        self::assertSame($conversation->session(), $changed[0]->session);
        self::assertSame('Redis setup', $changed[0]->title);
        self::assertSame('Redis setup', $conversation->session()->getTitle());
        self::assertCount(4, $conversation->session()->getMessages());
        self::assertSame($changed[0], $events[0]);

        self::assertSame([], $this->titleEvents(iterator_to_array($conversation->sendInput('More'), false)));
        self::assertCount(1, $provider->getRecorded());
    }

    public function testNoTopicResultsAllowALaterTitleWithinTheAttemptLimit(): void
    {
        $conversation = $this->conversation('One', 'Two', 'Three', 'Four');
        $provider = new FakeAIProvider(new AssistantMessage('{"title":null}'), new AssistantMessage('{"title":null}'), new AssistantMessage('{"title":null}'), new AssistantMessage('{"title":"Redis setup"}'));
        $conversation->setSessionTitleGenerator(new SessionTitleGenerator($provider));
        foreach (['Hello', 'How are you?', 'Thanks'] as $input) {
            self::assertSame([], $this->titleEvents(iterator_to_array($conversation->sendInput($input), false)));
        }
        self::assertNull($conversation->session()->getTitle());
        self::assertSame('3', $conversation->session()->getMetadataValue('titleGenerationAttempts'));
        $events = $this->titleEvents(iterator_to_array($conversation->sendInput('Configure Redis'), false));
        self::assertCount(1, $events);
        self::assertSame('Redis setup', $conversation->session()->getTitle());
        self::assertCount(4, $provider->getRecorded());
    }

    public function testConversationStopsTitleRequestsAfterFiveNullResults(): void
    {
        $conversation = $this->conversation('One', 'Two', 'Three', 'Four', 'Five', 'Six');
        $provider = new FakeAIProvider(
            new AssistantMessage('{"title":null}'),
            new AssistantMessage('{"title":null}'),
            new AssistantMessage('{"title":null}'),
            new AssistantMessage('{"title":null}'),
            new AssistantMessage('{"title":null}'),
            new AssistantMessage('{"title":"Unused"}'),
        );
        $conversation->setSessionTitleGenerator(new SessionTitleGenerator($provider));

        foreach (['Hello', 'How are you?', 'Thanks', 'Continue', 'More', 'Configure Redis'] as $input) {
            self::assertSame([], $this->titleEvents(iterator_to_array($conversation->sendInput($input), false)));
        }

        self::assertCount(5, $provider->getRecorded());
        self::assertSame('5', $conversation->session()->getMetadataValue('titleGenerationAttempts'));
        self::assertNull($conversation->session()->getTitle());
        self::assertCount(12, $conversation->session()->getMessages());
    }

    public function testTitleFailureWarnsWithoutLosingTheResponse(): void
    {
        $conversation = $this->conversation('Completed answer');
        $provider = new class extends FakeAIProvider {
            public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
            {
                throw new RuntimeException('Title provider unavailable');
            }
        };
        $conversation->setSessionTitleGenerator(new SessionTitleGenerator($provider));
        $stream = $conversation->sendInput('Question');
        $events = iterator_to_array($stream, false);
        self::assertInstanceOf(AgentState::class, $stream->getReturn());
        self::assertSame('Completed answer', $stream->getReturn()->getMessage()?->getContent());
        $warnings = array_values(array_filter($events, static fn(object $event): bool => $event instanceof Notification));
        self::assertCount(1, $warnings);
        self::assertInstanceOf(Notification::class, $warnings[0]);
        self::assertSame(NotificationLevel::Warning, $warnings[0]->level);
        self::assertStringContainsString('Title provider unavailable', $warnings[0]->text);
        self::assertSame('1', $conversation->session()->getMetadataValue('titleGenerationAttempts'));
    }

    public function testSessionReplacementDoesNotRedirectTheTitleAndNewSessionsAreEligible(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $conversation = $this->conversation('First', 'Second');
        $original = $conversation->session();
        $selected = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'other'))->create();
        $provider = new FakeAIProvider(new AssistantMessage('{"title":"Original topic"}'), new AssistantMessage('{"title":"New topic"}'));
        $conversation->setSessionTitleGenerator(new SessionTitleGenerator($provider));
        $stream = $conversation->sendInput('Original question');
        $stream->rewind();
        $conversation->useSession($selected);
        $events = $this->titleEvents(iterator_to_array($stream, false));
        self::assertCount(1, $events);
        self::assertSame($original, $events[0]->session);
        self::assertSame('Original topic', $original->getTitle());
        self::assertNull($selected->getTitle());

        iterator_to_array($conversation->sendInput('New question'));
        self::assertSame('New topic', $selected->getTitle());
        self::assertCount(2, $provider->getRecorded());
    }

    public function testUnstartedAndCommandStreamsDoNotGenerateTitles(): void
    {
        $conversation = $this->conversation('Unused');
        $provider = new FakeAIProvider(new AssistantMessage('{"title":"Unused"}'));
        $conversation->setSessionTitleGenerator(new SessionTitleGenerator($provider));
        $stream = $conversation->sendInput('Question');
        unset($stream);
        iterator_to_array($conversation->sendInput('/unknown'));
        self::assertSame([], $provider->getRecorded());
        self::assertArrayNotHasKey('titleGenerationAttempts', $conversation->session()->getMetadata());
    }

    public function testTitleIsGeneratedBeforeResponseExecutionAndCanSurviveInterruption(): void
    {
        $conversation = $this->conversation('Partial answer');
        $provider = new FakeAIProvider(new AssistantMessage('{"title":"Redis setup"}'));
        $conversation->setSessionTitleGenerator(new SessionTitleGenerator($provider));
        $conversation->setStopSignal(new StopSignal(new InMemoryStorage(), 'stop'));
        $stream = $conversation->sendInput('Configure Redis');
        $stream->rewind();
        self::assertInstanceOf(SessionTitleChanged::class, $stream->current());
        self::assertSame([], $conversation->session()->getMessages());
        self::assertSame('Redis setup', $conversation->session()->getTitle());
        self::assertTrue($conversation->requestInterruption());
        iterator_to_array($stream);
        self::assertCount(1, $provider->getRecorded());
    }

    public function testResponseFailureStillPropagatesAfterTitleGeneration(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $agentProvider = new class (new AssistantMessage('Unused')) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                throw new RuntimeException('Response failed');
            }
        };
        $conversation = new Conversation((new Agent())->setAiProvider($agentProvider), (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create());
        $titleProvider = new FakeAIProvider(new AssistantMessage('{"title":"Unused"}'));
        $conversation->setSessionTitleGenerator(new SessionTitleGenerator($titleProvider));
        try {
            iterator_to_array($conversation->sendInput('Question'));
            self::fail('Expected response failure');
        } catch (RuntimeException $error) {
            self::assertSame('Response failed', $error->getMessage());
        }
        self::assertCount(1, $titleProvider->getRecorded());
        self::assertSame('Unused', $conversation->session()->getTitle());
    }

    private function conversation(string ...$answers): Conversation
    {
        $nativeMessages = new InMemoryMessageStore();
        $provider = new FakeAIProvider(...array_map(static fn(string $answer): AssistantMessage => new AssistantMessage($answer), $answers));
        return new Conversation((new Agent())->setAiProvider($provider), (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create());
    }

    /**
     * @param list<object> $events
     * @return list<SessionTitleChanged>
     */
    private function titleEvents(array $events): array
    {
        return array_values(array_filter($events, static fn(object $event): bool => $event instanceof SessionTitleChanged));
    }
}
