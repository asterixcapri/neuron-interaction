<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use DateTimeImmutable;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\SelectionRequest;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function is_dir;
use function iterator_to_array;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

final class ResumeCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-resume-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /** @return array<string, array{bool}> */
    public static function storageKinds(): array
    {
        return ['memory' => [false], 'files' => [true]];
    }

    #[DataProvider('storageKinds')]
    public function testSelectionDescriptionShowsRelativeAge(bool $files): void
    {
        $option = $this->selectionOption(new DateTimeImmutable('-90 seconds'), 'Topic', $files);

        self::assertSame('Untitled session', $option->label);
        self::assertSame('1 minute ago', $option->description);
    }

    private function selectionOption(DateTimeImmutable $lastUsedAt, string $content, bool $files): SelectionOption
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
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
        iterator_to_array($conversation->sendInput(new CommandInput('/resume', $option->value)));
        self::assertSame($session->getKey(), $conversation->session()->getKey());
        self::assertSame($content, $conversation->session()->getMessages()[0]->getContent());

        return $option;
    }
}
