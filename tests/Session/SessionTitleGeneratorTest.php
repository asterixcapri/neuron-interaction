<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Session;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Session\SessionTitleGenerator;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

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
        $provider = new class($capture) extends FakeAIProvider {
            public function __construct(private readonly TitleInput $capture)
            {
                parent::__construct(new AssistantMessage('{"title":"Report review"}'));
            }

            public function structured(array|Message $messages, string $class, array $response_schema): Message
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
            $session->addMessage($message);
        }
        $title = (new SessionTitleGenerator($provider, $session))->generate();

        self::assertSame('Report review', $title);
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
        $session->addMessage($message);

        self::assertNull((new SessionTitleGenerator($provider, $session))->generate());
        self::assertSame($original, $message->jsonSerialize());
    }

    public function testEmptySessionDoesNotCallTheProvider(): void
    {
        $provider = new FakeAIProvider();


        $session = (new SessionStore(new InMemoryStorage(), 'local-user'))->create();

        self::assertNull((new SessionTitleGenerator($provider, $session))->generate());
        self::assertSame([], $provider->getRecorded());
    }
}

final class TitleInput
{
    public string $text = '';
}
