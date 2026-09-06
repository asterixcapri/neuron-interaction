<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use DateTimeImmutable;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class ResumeCommandTest extends TestCase
{
    public function testSelectionDescriptionCombinesRelativeAgeAndSize(): void
    {
        $option = $this->selectionOption(new DateTimeImmutable('-90 seconds'), 'Topic');

        self::assertSame('Topic', $option->label);
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
        $adapter = new FakeCommandAdapter(collection: new SessionStore($storage, 'alice'));

        (new ResumeCommand())->run($adapter, new CommandArguments());

        self::assertCount(1, $adapter->selections);
        self::assertCount(1, $adapter->selections[0]->options);
        $option = $adapter->selections[0]->options[0];
        self::assertSame($document->key, $option->value);

        return $option;
    }
}
