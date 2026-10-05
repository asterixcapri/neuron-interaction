<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Conversation;

use Closure;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Conversation;
use NeuronInteraction\Message\UserMessageProcessorInterface;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function iterator_to_array;

final class UserMessagePreparationTest extends TestCase
{
    public function testEverySubmissionIsPreparedEagerlyOnceInRegistrationOrder(): void
    {
        $first = new PreparationRecorder('A');
        $second = new PreparationRecorder('B');
        $processors = new UserMessageProcessors($first, $second);
        $provider = new FakeAIProvider(new AssistantMessage('One'), new AssistantMessage('Two'), new AssistantMessage('Three'));
        $conversation = new Conversation((new Agent())->setAiProvider($provider), (new SessionStore(new InMemoryStorage(), 'local'))->create());
        $conversation->setUserMessageProcessors($processors);
        $original = new UserMessage('First');
        $original->addMetadata('origin', 'human');
        $firstStream = $conversation->sendInput($original);
        $secondStream = $conversation->sendInput(new UserMessage('Second'));
        $promptStream = $conversation->sendInput(new UserMessage('Raw command'));
        self::assertSame($processors, $conversation->userMessageProcessors());
        self::assertSame('First', $original->getContent());
        $before = $provider->getRecorded();
        self::assertSame([], $before);
        iterator_to_array($firstStream);
        iterator_to_array($secondStream);
        iterator_to_array($promptStream);
        self::assertSame('human', $provider->getRecorded()[0]->messages[0]->getMetadata('origin'));
        self::assertSame(['First', 'Second', 'Raw command'], $first->inputs);
        self::assertSame(['A[First]', 'A[Second]', 'A[Raw command]'], $second->inputs);
        self::assertSame('B[A[First]]', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertSame('B[A[Second]]', $provider->getRecorded()[1]->messages[2]->getContent());
        self::assertSame('B[A[Raw command]]', $provider->getRecorded()[2]->messages[4]->getContent());
    }

    public function testRejectedPreparationCannotReserveOrQueueAndPreservesOriginal(): void
    {
        $processor = new class implements UserMessageProcessorInterface {
            public function forAgent(UserMessage $input): UserMessage
            {
                $text = $input->getContent();
                $input->setContents(' ');
                if ($text === 'fail') {
                    throw new RuntimeException('Preparation failed');
                }
                return $input;
            }
            public function forDisplay(UserMessage $content): UserMessage
            {
                return clone $content;
            }
        };
        $provider = new FakeAIProvider();
        $conversation = new Conversation((new Agent())->setAiProvider($provider), (new SessionStore(new InMemoryStorage(), 'local'))->create());
        $conversation->setUserMessageProcessors(new UserMessageProcessors($processor));
        foreach (['fail', 'empty'] as $text) {
            $original = new UserMessage($text);
            try {
                $conversation->sendInput($original);
                self::fail('Expected rejection');
            } catch (InvalidArgumentException|RuntimeException $exception) {
                self::assertSame($text === 'fail' ? 'Preparation failed' : 'The prepared user message is empty.', $exception->getMessage());
            }
            self::assertSame($text, $original->getContent());
            self::assertSame([], $provider->getRecorded());
        }
    }

    public function testPreparationDoesNotEnforceAdmissionAfterAProcessorStartsExecution(): void
    {
        $processor = new class implements UserMessageProcessorInterface {
            public ?Closure $onPrepare = null;
            public function forAgent(UserMessage $input): UserMessage
            {
                $this->onPrepare?->__invoke();
                return clone $input;
            }
            public function forDisplay(UserMessage $input): UserMessage
            {
                return clone $input;
            }
        };
        $conversation = new Conversation((new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Other'), new AssistantMessage('Prepared'))), (new SessionStore(new InMemoryStorage(), 'local'))->create());
        $conversation->setUserMessageProcessors(new UserMessageProcessors($processor));
        $otherStream = null;
        $processor->onPrepare = static function () use ($conversation, $processor, &$otherStream): void {
            $processor->onPrepare = null;
            $otherStream = $conversation->sendInput(new UserMessage('Other'));
            $otherStream->rewind();
        };
        $preparedStream = $conversation->sendInput(new UserMessage('Preparing'));
        self::assertNotNull($otherStream);
        iterator_to_array($otherStream);
        $processor->onPrepare = null;
        iterator_to_array($preparedStream);
        self::assertSame('Preparing', $conversation->agent()->getChatHistory()->getMessages()[2]->getContent());
    }

    public function testNonTextAttachmentAndMetadataAreAcceptedWithoutText(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Image response'));
        $conversation = new Conversation((new Agent())->setAiProvider($provider), (new SessionStore(new InMemoryStorage(), 'local'))->create());
        $image = (new ImageContent('https://example.com/image.png', SourceType::URL))->addMetadata('image', 'original');
        $message = new UserMessage($image);
        $message->addMetadata('origin', 'attachment');
        iterator_to_array($conversation->sendInput($message));
        self::assertSame($message->jsonSerialize(), $provider->getRecorded()[0]->messages[0]->jsonSerialize());
    }
}

final class PreparationRecorder implements UserMessageProcessorInterface
{
    /** @var list<string|null> */
    public array $inputs = [];
    public function __construct(private readonly string $prefix) {}
    public function forAgent(UserMessage $input): UserMessage
    {
        $this->inputs[] = $input->getContent();
        $message = clone $input;
        $message->setContents($this->prefix . '[' . $input->getContent() . ']');
        return $message;
    }
    public function forDisplay(UserMessage $content): UserMessage
    {
        return clone $content;
    }
}
