<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use DateTimeImmutable;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\SelectionRequest;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryEntryStorage;
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

final class ResumeCommandTest extends TestCase
{
    public function testSelectionDescriptionCombinesRelativeAgeAndSize(): void
    {
        $option = $this->selectionOption(new DateTimeImmutable('-90 seconds'), 'Topic');

        self::assertSame('New session', $option->label);
        self::assertMatchesRegularExpression('/^1 minute ago · [0-9]+B$/', $option->description ?? '');
    }

    private function selectionOption(DateTimeImmutable $lastUsedAt, string $content): SelectionOption
    {
        $storage = new InMemoryEntryStorage();
        $store = new SessionStore($storage, 'alice');
        $session = $store->create();
        SessionHistory::of($session)->addMessage(new UserMessage($content));
        $storage->update(Session::namespaceForUser('alice'), $session->getKey(), [
            ['path' => ['lastUsedAt'], 'value' => $lastUsedAt->format('Y-m-d\TH:i:s.uP')],
        ]);
        $conversation = new Conversation(new Agent(), $store->create());
        $conversation->setCommands(new Commands(new ResumeCommand($store)));
        $events = iterator_to_array($conversation->sendInput('/resume'));

        self::assertCount(1, $events);
        self::assertInstanceOf(SelectionRequest::class, $events[0]);
        self::assertCount(1, $events[0]->options);
        $option = $events[0]->options[0];
        self::assertSame($session->getKey(), $option->value);

        return $option;
    }
}
