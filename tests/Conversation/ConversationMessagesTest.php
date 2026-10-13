<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Conversation;
use NeuronInteraction\Message\UserMessageProcessorInterface;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

final class ConversationMessagesTest extends TestCase
{
    public function testDisplayMessagesRestoreReferencesWithoutChangingSavedHistoryOrAttachments(): void
    {
        $processor = new class implements UserMessageProcessorInterface {
            public function forAgent(UserMessage $message): UserMessage
            {
                $prepared = clone $message;
                $prepared->addContent((new TextContent("File: README.md\n\nFile contents"))->addMetadata('file', 'README.md'));
                return $prepared;
            }

            public function forDisplay(UserMessage $message): UserMessage
            {
                $display = clone $message;
                $blocks = [];
                foreach ($display->getContentBlocks() as $block) {
                    if (!$block instanceof TextContent || $block->getMetadata('file') !== 'README.md') {
                        $blocks[] = $block;
                    }
                }
                $display->setContents($blocks);
                return $display;
            }
        };
        $sessionStore = new SessionStore(new InMemoryStorage(), 'local');
        $session = $sessionStore->create();
        $agent = (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Summary')));
        $conversation = new Conversation($agent, $session);
        $conversation->setUserMessageProcessors(new UserMessageProcessors($processor));
        $image = new ImageContent('https://example.com/image.png', SourceType::URL);
        $input = new UserMessage([new TextContent('Read @README.md'), $image]);
        $input->addMetadata('origin', 'human');
        iterator_to_array($conversation->sendInput($input));

        $saved = $conversation->getMessages();
        $displayed = $conversation->getDisplayMessages();

        self::assertCount(2, $saved);
        self::assertCount(3, $saved[0]->getContentBlocks());
        self::assertCount(2, $displayed[0]->getContentBlocks());
        self::assertSame("Read @README.md File: README.md\n\nFile contents", $saved[0]->getContent());
        self::assertSame('Read @README.md', $displayed[0]->getContent());
        self::assertSame('human', $displayed[0]->getMetadata('origin'));
        self::assertSame($saved[0]->getContentBlocks()[1]->jsonSerialize(), $displayed[0]->getContentBlocks()[1]->jsonSerialize());
        self::assertSame($saved[1]->jsonSerialize(), $displayed[1]->jsonSerialize());
        self::assertSame($saved[0]->jsonSerialize(), $conversation->getMessages()[0]->jsonSerialize());
        self::assertSame($saved[0]->jsonSerialize(), $session->getMessages()[0]->jsonSerialize());
        self::assertSame('Read @README.md', $conversation->getDisplayMessages()[0]->getContent());

        $before = $saved[1]->getId();
        self::assertEquals([$saved[1]], $conversation->getMessages(limit: 1));
        self::assertEquals([$saved[0]], $conversation->getMessages(limit: 1, before: $before));
        self::assertEquals([$saved[0]], $session->getMessages(limit: 1, before: $before));
        $displayPage = $conversation->getDisplayMessages(limit: 1, before: $before);
        self::assertCount(1, $displayPage);
        self::assertSame('Read @README.md', $displayPage[0]->getContent());
        self::assertSame($saved[0]->getId(), $displayPage[0]->getId());
        self::assertSame([], $conversation->getDisplayMessages(before: $saved[0]->getId()));
        self::assertSame([], $conversation->getMessages(before: 'unknown'));

        $conversation->useSession($sessionStore->create());
        self::assertSame([], $conversation->getMessages());
        self::assertSame([], $conversation->getDisplayMessages());
    }
}
