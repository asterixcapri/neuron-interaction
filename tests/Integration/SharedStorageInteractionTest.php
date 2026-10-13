<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Integration;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Session\SessionMessageStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Tests\History\SessionHistory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function is_dir;
use function is_link;
use function iterator_to_array;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

final class SharedStorageInteractionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-shared-storage-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    /** @return array<string, array{bool}> */
    public static function storageKinds(): array
    {
        return ['memory' => [false], 'files' => [true]];
    }

    #[DataProvider('storageKinds')]
    public function testOneStorageSupportsCaptureResumeArchiveClearPreferencesAndSignals(bool $files): void
    {
        $storage = $files ? new FileStorage($this->directory) : new InMemoryStorage();
        $sessions = new SessionStore($storage, 'alice');
        $preferences = new ConfigurationStore($storage, 'alice');
        $inputs = new InputHistory($storage, 'alice');
        $preferences->set('language', 'Italian');
        $session = $sessions->create(['project' => 'alpha']);
        $key = $session->getKey();
        self::assertNotNull($sessions->get($key));
        self::assertSame([], $sessions->list());
        $provider = new FakeAIProvider(new AssistantMessage('First answer'), new AssistantMessage('Continued answer'));
        $conversation = new Conversation((new Agent())->setAiProvider($provider), $session);
        $conversation->setConfigurationStore($preferences);
        $conversation->setInputHistory($inputs);
        $conversation->setCommands(new Commands(new ClearCommand($sessions), new ResumeCommand($sessions)));
        iterator_to_array($conversation->sendInput('Original question'));
        $session->setTitle('Saved title');
        $session->setMetadata('branch', 'main');
        $session->removeMetadata('project');
        self::assertCount(2, $session->getMessages());

        iterator_to_array($conversation->sendInput('/clear'));
        self::assertNotSame($key, $conversation->session()->getKey());
        self::assertCount(1, $sessions->list());
        iterator_to_array($conversation->sendInput('/resume ' . $key));
        self::assertSame($key, $conversation->session()->getKey());
        iterator_to_array($conversation->sendInput('Continue'));

        $reopened = $files ? new FileStorage($this->directory) : $storage;
        $freshSessions = new SessionStore($reopened, 'alice');
        $restored = $freshSessions->get($key);
        self::assertNotNull($restored);
        self::assertSame('Saved title', $restored->getTitle());
        self::assertSame(['branch' => 'main'], $restored->getMetadata());
        self::assertCount(4, $restored->getMessages());
        self::assertSame('Italian', (new ConfigurationStore($reopened, 'alice'))->get('language'));
        self::assertCount(4, (new InputHistory($reopened, 'alice'))->list());
        self::assertNull((new SessionStore($reopened, 'bob'))->get($key));
        self::assertSame([], (new InputHistory($reopened, 'bob'))->list());

        $messages = new SessionMessageStore($reopened, 'alice');
        $messages->archive($key, 2);
        self::assertCount(2, $messages->loadActive($key));
        self::assertCount(4, $restored->getMessages());
        SessionHistory::of($restored)->flushAll();
        self::assertSame([], $messages->loadAll($key));
        self::assertSame([], $freshSessions->list());
        self::assertCount(4, $inputs->list());
        self::assertSame('Italian', $preferences->get('language'));

        $signal = new StopSignal($storage, 'response');
        $signal->request();
        self::assertTrue((new StopSignal($reopened, 'response'))->isRequested());
        (new StopSignal($reopened, 'response'))->clear();
        self::assertFalse($signal->isRequested());
        $freshSessions->delete($key);
        self::assertNull($sessions->get($key));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path)
                ? $this->removeDirectory($path)
                : unlink($path);
        }
        rmdir($directory);
    }
}
