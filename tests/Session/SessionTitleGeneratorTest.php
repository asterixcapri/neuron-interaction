<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use Closure;
use InvalidArgumentException;
use NeuronAI\Chat\Enums\SourceType;
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
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_shift;
use function is_array;

final class SessionTitleGeneratorTest extends TestCase
{
    public function testGenerationUsesHistoryTextAndAttachmentsWithoutChangingSources(): void
    {
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

        $session = (new SessionStore(new InMemoryStorage(), 'local-user'))->create();
        foreach ([
            $input,
            new AssistantMessage('The report needs revision.'),
            new ToolCallMessage('Technical tool instructions', []),
            new ToolResultMessage([]),
        ] as $message) {
            SessionHistory::of($session)->addMessage($message);
        }
        $title = (new SessionTitleGenerator($provider, $session))->generate();

        self::assertSame('Report review', $title);
        self::assertSame($title, $session->getTitle());
        self::assertSame('1', $session->getMetadata()['titleGenerationAttempts']);
        self::assertStringContainsString('Expanded skill instructions', $capture->text);
        self::assertStringContainsString('[File: report.pdf]', $capture->text);
        self::assertStringContainsString('The report needs revision.', $capture->text);
        self::assertStringNotContainsString('Private reasoning', $capture->text);
        self::assertStringNotContainsString('Technical tool instructions', $capture->text);
        self::assertSame($original, $input->jsonSerialize());
        self::assertCount(1, $provider->getRecorded());
    }

    public function testNoTopicReturnsNullAndDoesNotChangeSessionData(): void
    {
        $message = new UserMessage('ciao, /caveman');
        $original = $message->jsonSerialize();
        $provider = new FakeAIProvider(new AssistantMessage('{"title":null}'));


        $session = (new SessionStore(new InMemoryStorage(), 'local-user'))->create();
        SessionHistory::of($session)->addMessage($message);

        self::assertNull((new SessionTitleGenerator($provider, $session))->generate());
        self::assertSame($original, $message->jsonSerialize());
    }

    public function testEmptySessionDoesNotCallTheProvider(): void
    {
        $provider = new FakeAIProvider();


        $session = (new SessionStore(new InMemoryStorage(), 'local-user'))->create();

        self::assertNull((new SessionTitleGenerator($provider, $session))->generate());
        self::assertSame([], $provider->getRecorded());
        self::assertArrayNotHasKey('titleGenerationAttempts', $session->getMetadata());
    }

    public function testNullIsRetriedAndASavedTitleStopsFurtherGeneration(): void
    {
        $requests = new TitleGenerationRequests(['{"title":null}', '{"title":"Redis setup"}']);
        $provider = $this->provider($requests);
        $session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
        SessionHistory::of($session)->addMessage(new UserMessage('Hello'));
        $generator = new SessionTitleGenerator($provider, $session);

        self::assertNull($generator->generate());
        self::assertNull($session->getTitle());
        self::assertSame('1', $session->getMetadata()['titleGenerationAttempts']);

        SessionHistory::of($session)->addMessage(new AssistantMessage('Hello!'));
        SessionHistory::of($session)->addMessage(new UserMessage('Configure Redis'));
        self::assertSame('Redis setup', $generator->generate());
        self::assertNull($generator->generate());
        self::assertSame(2, $requests->count);
        self::assertSame('Redis setup', $session->getTitle());
    }

    public function testNullResultsStopAtTheDefaultLimitAcrossReloads(): void
    {
        $requests = new TitleGenerationRequests(['{"title":null}', '{"title":null}', '{"title":null}', '{"title":"Unused"}']);
        $provider = $this->provider($requests);
        $storage = new InMemoryStorage();
        $session = (new SessionStore($storage, 'local'))->create();
        SessionHistory::of($session)->addMessage(new UserMessage('Hello'));

        for ($turn = 0; $turn < 4; ++$turn) {
            $reloaded = (new SessionStore($storage, 'local'))->read($session->getKey());
            self::assertNotNull($reloaded);
            self::assertNull((new SessionTitleGenerator($provider, $reloaded))->generate());
        }

        self::assertSame(3, $requests->count);
        self::assertSame('3', $session->getMetadata()['titleGenerationAttempts']);
        self::assertNull($session->getTitle());
    }

    public function testErrorsPropagateAndCountTowardTheConfiguredLimit(): void
    {
        $requests = new TitleGenerationRequests([]);
        $session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
        SessionHistory::of($session)->addMessage(new UserMessage('A subject'));
        $generator = new SessionTitleGenerator($this->provider($requests), $session, maxAttempts: 2);

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $generator->generate();
                self::fail('Provider errors must propagate.');
            } catch (RuntimeException $error) {
                self::assertSame('Title provider unavailable.', $error->getMessage());
            }
        }

        self::assertNull($generator->generate());
        self::assertSame(2, $requests->count);
        self::assertSame('2', $session->getMetadata()['titleGenerationAttempts']);
        self::assertNull($session->getTitle());
    }

    public function testInvalidAttemptMetadataDoesNotStartARequest(): void
    {
        foreach (['invalid', '-1', '1.5'] as $value) {
            $provider = new FakeAIProvider(new AssistantMessage('{"title":"Unused"}'));
            $session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
            SessionHistory::of($session)->addMessage(new UserMessage('A subject'));
            $session->setMetadata('titleGenerationAttempts', $value);

            self::assertNull((new SessionTitleGenerator($provider, $session))->generate());
            self::assertSame([], $provider->getRecorded());
            self::assertSame($value, $session->getMetadata()['titleGenerationAttempts']);
        }
    }

    public function testAttemptLimitMustBePositive(): void
    {
        $session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
        $this->expectException(InvalidArgumentException::class);

        new SessionTitleGenerator(new FakeAIProvider(), $session, maxAttempts: 0);
    }

    public function testAnExistingTitleDoesNotStartARequest(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"title":"Unused"}'));
        $session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
        SessionHistory::of($session)->addMessage(new UserMessage('A subject'));
        $session->setTitle('Manual title');

        self::assertNull((new SessionTitleGenerator($provider, $session))->generate());
        self::assertSame('Manual title', $session->getTitle());
        self::assertSame([], $provider->getRecorded());
        self::assertArrayNotHasKey('titleGenerationAttempts', $session->getMetadata());
    }

    public function testATitleAssignedDuringGenerationIsNotOverwritten(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        SessionHistory::of($session)->addMessage(new UserMessage('A subject'));
        $otherSession = $store->read($session->getKey());
        self::assertNotNull($otherSession);
        $requests = new TitleGenerationRequests(['{"title":"Automatic title"}']);
        $requests->onRequest = static fn() => $otherSession->setTitle('Manual title');

        self::assertNull((new SessionTitleGenerator($this->provider($requests), $session))->generate());
        self::assertSame(1, $requests->count);
        self::assertSame('Manual title', $session->getTitle());
    }

    public function testASessionDeletedDuringGenerationIsNotRecreated(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        SessionHistory::of($session)->addMessage(new UserMessage('A subject'));
        $requests = new TitleGenerationRequests(['{"title":"Late title"}']);
        $requests->onRequest = static fn() => $store->delete($session->getKey());

        try {
            (new SessionTitleGenerator($this->provider($requests), $session))->generate();
            self::fail('Saving a title to a deleted Session must fail.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('The Session no longer exists.', $error->getMessage());
        }

        self::assertNull($store->read($session->getKey()));
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
