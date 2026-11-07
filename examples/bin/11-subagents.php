<?php

declare(strict_types=1);

use Amp\CancelledException;
use Amp\DeferredCancellation;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\ExitRequest;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Event\SubagentCompleted;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Subagents\Adapter\ConsoleAdapter;
use NeuronInteraction\Subagents\Subagents;
use NeuronInteraction\Subagents\SubagentStore;
use NeuronInteractionDemo\AIProviderFactory;
use NeuronInteractionDemo\Subagents\Agents;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;

use function Amp\async;
use function Amp\ByteStream\getStdin;
use function Amp\ByteStream\splitLines;
use function Amp\delay;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

// The parent and worker must share storage and the same lock backend.
$directory = \dirname(__DIR__) . '/.storage/subagents';
$storage = new FileStorage($directory);
$messageStore = new FileMessageStore($directory . '/messages');
$locks = new LockFactory(new SemaphoreStore());
$sessionStore = new SessionStore($storage, $messageStore, 'local-user');

// Supply the printed Session key to collect results after reopening the terminal.
$sessionKey = $argv[1] ?? null;
$session = $sessionKey === null ? $sessionStore->create() : $sessionStore->get($sessionKey);
if ($session === null) {
    throw new RuntimeException('Unknown Session. Use a Session key printed by this example.');
}

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

// Share child definitions with the worker; every factory creates a fresh Agent.
$registry = Agents::registry();
$runs = new SubagentStore($storage, $locks);
$adapter = new ConsoleAdapter(
    [\PHP_BINARY, __DIR__ . '/subagent-worker.php'],
    $directory . '/worker.log',
);
$subagents = new Subagents($registry, $runs, $adapter);

$conversation = new Conversation($agent, $session);
$conversation->setSubagents($subagents);
$conversation->setCommands(new Commands(new ExitCommand()));

echo 'Session: ' . $conversation->session()->getKey() . \PHP_EOL;
echo 'Try: Delegate a comparison of A (10 EUR/month) and B (80 EUR/year) to the researcher.' . \PHP_EOL;
echo 'Wait after the first response: the assistant will report the result automatically.' . \PHP_EOL;
echo 'Use /exit to leave. Reopen with: composer subagents -- ' . $session->getKey() . \PHP_EOL;

// Reading input continues while the provider awaits network I/O. Execution stays
// serial: new messages queue behind the current turn, ahead of completion polls.
/** @var SplQueue<string> $inputs */
$inputs = new SplQueue();
$stopReading = new DeferredCancellation();
$reader = async(function () use ($inputs, $stopReading): void {
    try {
        echo '> ';
        foreach (splitLines(getStdin(), $stopReading->getCancellation()) as $line) {
            $input = \trim($line);
            if ($input !== '') {
                $inputs->enqueue($input);
            }
            \flush();
        }
    } catch (CancelledException) {
        // /exit stops the reader even when stdin remains open.
    }
});

$nextCompletionCheck = 0.0;
try {
    while (!$reader->isComplete() || !$inputs->isEmpty()) {
        if (!$inputs->isEmpty()) {
            $input = $inputs->dequeue();
        } elseif (\microtime(true) >= $nextCompletionCheck) {
            $input = null;
            $nextCompletionCheck = \microtime(true) + 1;
        } else {
            delay(0.05);
            continue;
        }
        $printed = false;
        try {
            $stream = $input === null
                ? $conversation->processCompletions()
                : $conversation->sendInput($input);

            foreach ($stream as $event) {
                if ($event instanceof ExitRequest) {
                    break 2;
                }

                if ($event instanceof TextChunk) {
                    $text = $event->content;
                } elseif ($event instanceof Notification) {
                    $text = $event->text . \PHP_EOL;
                } elseif ($event instanceof SubagentCompleted && $event->recovered) {
                    // A recovered turn can also have ended silently.
                    $text = $event->reply->getContent() ?? '';
                } else {
                    continue;
                }

                if ($text === '') {
                    continue;
                }
                if (!$printed) {
                    echo \PHP_EOL;
                    $printed = true;
                }
                echo $text;
                \flush();
            }

            if ($printed) {
                echo \PHP_EOL . \PHP_EOL;
            }
        } catch (Throwable $error) {
            \fwrite(\STDERR, \PHP_EOL . $error->getMessage() . \PHP_EOL);
            $printed = true;
            if ($input === null) {
                // Keep accepting input while waiting before retrying a failed completion.
                $nextCompletionCheck = \microtime(true) + 10;
            }
        } finally {
            // Release a partially consumed Conversation stream before the next turn.
            unset($stream);
        }

        if ($printed) {
            echo '> ';
        }
    }
} finally {
    $stopReading->cancel();
    $reader->await();
}
