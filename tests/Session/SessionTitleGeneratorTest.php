<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use Closure;
use InvalidArgumentException;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Session\SessionTitleGenerator;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\MissingEntryException;
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_shift;
use function is_array;

final class SessionTitleGeneratorTest extends TestCase
{
    public function testGenerationUsesHistoryTextAndAttachmentsWithoutChangingSources(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $input = new UserMessage([
            new TextContent('Expanded skill instructions'),
            new ReasoningContent('Private reasoning'),
            new FileContent('https://example.com/report.pdf', SourceType::URL, 'application/pdf', 'report.pdf'),
        ]);
        $input->setMetadata(['source' => 'original']);
        $original = $input->jsonSerialize();
        $capture = new TitleInput();
        $provider = new class ($capture) extends FakeAIProvider {
            public function __construct(private readonly TitleInput $capture)
            {
                parent::__construct(new AssistantMessage('{"title":"Report review"}'));
            }

            public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
            {
                $messages = is_array($messages) ? $messages : [$messages];
                $this->capture->text = $messages[0]->getContent() ?? '';

                return parent::structured($messages, $class, $response_schema);
            }
        };

        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local-user'))->create();
        foreach ([
            $input,
            new AssistantMessage('The report needs revision.'),
            new ToolCallMessage('Technical tool instructions', []),
            new ToolResultMessage([]),
        ] as $message) {
            SessionHistory::append($session, $message);
        }
        $title = (new SessionTitleGenerator($provider))->generate($session);

        self::assertSame('Report review', $title);
        self::assertSame($title, $session->getTitle());
        self::assertSame('1', $session->getMetadataValue('titleGenerationAttempts'));
        self::assertStringContainsString('Expanded skill instructions', $capture->text);
        self::assertStringContainsString('[File: report.pdf]', $capture->text);
        self::assertStringNotContainsString('The report needs revision.', $capture->text);
        self::assertStringNotContainsString('Private reasoning', $capture->text);
        self::assertStringNotContainsString('Technical tool instructions', $capture->text);
        self::assertSame($original, $input->jsonSerialize());
        self::assertCount(1, $provider->getRecorded());
    }

    public function testNoTopicReturnsNullAndDoesNotChangeSessionData(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $message = new UserMessage('ciao, /caveman');
        $original = $message->jsonSerialize();
        $provider = new FakeAIProvider(new AssistantMessage('{"title":null}'));


        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local-user'))->create();
        SessionHistory::append($session, $message);

        self::assertNull((new SessionTitleGenerator($provider))->generate($session));
        self::assertSame($original, $message->jsonSerialize());
    }

    public function testTitleCanBeGeneratedOnTheFifthAttemptAfterNoTopicResults(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $provider = new FakeAIProvider(
            new AssistantMessage('{"title":null}'),
            new AssistantMessage('{"title":null}'),
            new AssistantMessage('{"title":null}'),
            new AssistantMessage('{"title":null}'),
            new AssistantMessage('{"title":"Configurazione Redis"}'),
        );
        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create();
        $generator = new SessionTitleGenerator($provider);

        foreach (['ciao', 'come stai?', 'grazie!', 'non lo so nemmeno io'] as $input) {
            SessionHistory::append($session, new UserMessage($input));
            SessionHistory::append($session, new AssistantMessage('Ciao! Posso aiutarti con PHP, Neuron-TUI o Redis.'));
            self::assertNull($generator->generate($session));
            self::assertNull($session->getTitle());
        }
        self::assertCount(4, $provider->getRecorded());
        self::assertSame('4', $session->getMetadataValue('titleGenerationAttempts'));

        SessionHistory::append($session, new UserMessage('Come configurare Redis?'));
        self::assertSame('Configurazione Redis', $generator->generate($session));
        self::assertSame('5', $session->getMetadataValue('titleGenerationAttempts'));
        self::assertCount(5, $provider->getRecorded());
    }

    public function testEmptySessionDoesNotCallTheProvider(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $provider = new FakeAIProvider();


        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local-user'))->create();

        self::assertNull((new SessionTitleGenerator($provider))->generate($session));
        self::assertSame([], $provider->getRecorded());
        self::assertArrayNotHasKey('titleGenerationAttempts', $session->getMetadata());
    }

    public function testPendingMessageIsIncludedWithoutBeingSavedToHistory(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $provider = new FakeAIProvider(new AssistantMessage('{"title":"Redis setup"}'));
        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create();
        $pending = new UserMessage('Configure Redis');

        self::assertSame('Redis setup', (new SessionTitleGenerator($provider))->generate($session, $pending));
        self::assertSame([], $session->getMessages());
        $requests = $provider->getRecorded();
        self::assertCount(1, $requests);
        self::assertSame('[{"role":"user","content":"Configure Redis"}]', $requests[0]->messages[0]->getContent());
    }

    public function testNullIsRetriedAndASavedTitleStopsFurtherGeneration(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $requests = new TitleGenerationRequests(['{"title":null}', '{"title":"Redis setup"}']);
        $provider = $this->provider($requests);
        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create();
        SessionHistory::append($session, new UserMessage('Hello'));
        $generator = new SessionTitleGenerator($provider);

        self::assertNull($generator->generate($session));
        self::assertNull($session->getTitle());
        self::assertSame('1', $session->getMetadataValue('titleGenerationAttempts'));

        SessionHistory::append($session, new AssistantMessage('Hello!'));
        SessionHistory::append($session, new UserMessage('Configure Redis'));
        self::assertSame('Redis setup', $generator->generate($session));
        self::assertNull($generator->generate($session));
        self::assertSame(2, $requests->count);
        self::assertSame('Redis setup', $session->getTitle());
    }

    public function testNullResultsExhaustTheFiveAttemptBudgetAcrossReloads(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $requests = new TitleGenerationRequests([
            '{"title":null}', '{"title":null}', '{"title":null}',
            '{"title":null}', '{"title":null}', '{"title":"Unused"}',
        ]);
        $provider = $this->provider($requests);
        $storage = new InMemoryStorage();
        $session = (new SessionStore($storage, $nativeMessages, 'local'))->create();
        SessionHistory::append($session, new UserMessage('Hello'));

        for ($turn = 0; $turn < 6; ++$turn) {
            $reloaded = (new SessionStore($storage, $nativeMessages, 'local'))->get($session->getKey());
            self::assertNotNull($reloaded);
            self::assertNull((new SessionTitleGenerator($provider))->generate($reloaded));
        }

        self::assertSame(5, $requests->count);
        self::assertNull($session->getMetadataValue('titleGenerationAttempts'));
        self::assertSame('5', $reloaded->getMetadataValue('titleGenerationAttempts'));
        self::assertNull($session->getTitle());
    }

    public function testErrorsPropagateAndCountTowardTheConfiguredLimit(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $requests = new TitleGenerationRequests([]);
        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create();
        SessionHistory::append($session, new UserMessage('A subject'));
        $generator = new SessionTitleGenerator($this->provider($requests), maxAttempts: 2);

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $generator->generate($session);
                self::fail('Provider errors must propagate.');
            } catch (RuntimeException $error) {
                self::assertSame('Title provider unavailable.', $error->getMessage());
            }
        }

        self::assertNull($generator->generate($session));
        self::assertSame(2, $requests->count);
        self::assertSame('2', $session->getMetadataValue('titleGenerationAttempts'));
        self::assertNull($session->getTitle());
    }

    public function testInvalidAttemptMetadataDoesNotStartARequest(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        foreach (['invalid', '-1', '1.5'] as $value) {
            $provider = new FakeAIProvider(new AssistantMessage('{"title":"Unused"}'));
            $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create();
            SessionHistory::append($session, new UserMessage('A subject'));
            $session->setMetadata('titleGenerationAttempts', $value);

            self::assertNull((new SessionTitleGenerator($provider))->generate($session));
            self::assertSame([], $provider->getRecorded());
            self::assertSame($value, $session->getMetadataValue('titleGenerationAttempts'));
        }
    }

    public function testAttemptLimitMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionTitleGenerator(new FakeAIProvider(), maxAttempts: 0);
    }

    public function testAnExistingTitleDoesNotStartARequest(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $provider = new FakeAIProvider(new AssistantMessage('{"title":"Unused"}'));
        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'local'))->create();
        SessionHistory::append($session, new UserMessage('A subject'));
        $session->setTitle('Manual title');

        self::assertNull((new SessionTitleGenerator($provider))->generate($session));
        self::assertSame('Manual title', $session->getTitle());
        self::assertSame([], $provider->getRecorded());
        self::assertArrayNotHasKey('titleGenerationAttempts', $session->getMetadata());
    }

    public function testATitleAssignedToTheSameSessionDuringGenerationIsNotOverwritten(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'local');
        $session = $store->create();
        SessionHistory::append($session, new UserMessage('A subject'));
        $requests = new TitleGenerationRequests(['{"title":"Automatic title"}']);
        $requests->onRequest = static fn() => $session->setTitle('Manual title');

        self::assertNull((new SessionTitleGenerator($this->provider($requests)))->generate($session));
        self::assertSame(1, $requests->count);
        self::assertSame('Manual title', $session->getTitle());
    }

    public function testASessionDeletedDuringGenerationIsNotRecreated(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'local');
        $session = $store->create();
        SessionHistory::append($session, new UserMessage('A subject'));
        $requests = new TitleGenerationRequests(['{"title":"Late title"}']);
        $requests->onRequest = static fn() => $store->delete($session->getKey());

        try {
            (new SessionTitleGenerator($this->provider($requests)))->generate($session);
            self::fail('Saving a title to a deleted Session must fail.');
        } catch (MissingEntryException $error) {
            self::assertSame('The storage entry does not exist.', $error->getMessage());
        }

        self::assertNull($store->get($session->getKey()));
    }

    public function testNoTopicResultDoesNotRecreateASessionDeletedDuringGeneration(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'local');
        $session = $store->create();
        SessionHistory::append($session, new UserMessage('Can you help me?'));
        $requests = new TitleGenerationRequests(['{"title":null}']);
        $requests->onRequest = static fn() => $store->delete($session->getKey());

        self::assertNull((new SessionTitleGenerator($this->provider($requests)))->generate($session));
        self::assertNull($store->get($session->getKey()));
    }

    private function provider(TitleGenerationRequests $requests): FakeAIProvider
    {
        return new class ($requests) extends FakeAIProvider {
            public function __construct(private readonly TitleGenerationRequests $requests)
            {
                parent::__construct();
            }

            public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
            {
                ++$this->requests->count;
                if ($this->requests->onRequest !== null) {
                    ($this->requests->onRequest)();
                }
                $response = array_shift($this->requests->responses);
                if ($response === null) {
                    throw new RuntimeException('Title provider unavailable.');
                }

                return new ProviderResponse(message: new AssistantMessage($response));
            }
        };
    }

}

final class TitleInput
{
    public string $text = '';
}


final class TitleGenerationRequests
{
    public int $count = 0;

    public ?Closure $onRequest = null;

    /** @param list<string> $responses */
    public function __construct(public array $responses) {}
}
