<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use DateTimeImmutable;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

final class ResumeCommandTest extends TestCase
{
    public function testSelectionDescriptionCombinesRelativeAgeAndSize(): void
    {
        $option = $this->selectionOption(new DateTimeImmutable('-90 seconds'), 'Topic');

        self::assertSame('New session', $option->label);
        self::assertSame('1 minute ago · 35B', $option->description);
    }

    private function selectionOption(DateTimeImmutable $lastUsedAt, string $content): SelectionOption
    {
        $storage = new InMemoryStorage();
        $document = $storage->create('sessions', [
            ['role' => 'user', 'content' => $content],
        ], [
            'userId' => 'alice',
            'lastUsedAt' => $lastUsedAt->format('Y-m-d\TH:i:s.uP'),
        ]);
        $store = new SessionStore($storage, 'alice');
        $conversation = new Conversation(new Agent(), $store->create(), commands: new Commands(new ResumeCommand($store)));
        $events = iterator_to_array($conversation->sendInput('/resume'));

        self::assertCount(1, $events);
        self::assertInstanceOf(SelectionRequest::class, $events[0]);
        self::assertCount(1, $events[0]->options);
        $option = $events[0]->options[0];
        self::assertSame($document->key, $option->value);

        return $option;
    }
}
