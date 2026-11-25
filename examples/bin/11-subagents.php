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
use NeuronInteraction\Event\SubagentDispatched;
use NeuronInteraction\Exception\ExecutionAbortedException;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Subagents\Adapter\ConsoleAdapter;
use NeuronInteraction\Subagents\Subagents;
use NeuronInteraction\Subagents\SubagentStore;
use NeuronInteractionDemo\AIProviderFactory;
use NeuronInteractionDemo\SubagentsRegistry;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;

use function Amp\async;
use function Amp\ByteStream\getStdin;
use function Amp\ByteStream\splitLines;
use function Amp\delay;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$storage = new FileStorage(\dirname(__DIR__) . '/.storage/subagents');

$agent = new Agent();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$messageStore = new FileMessageStore(\dirname(__DIR__) . '/.storage/subagents/messages');
$sessionStore = new SessionStore($storage, $messageStore, 'local-user');
$session = $sessionStore->create();

$subagentsRegistry = SubagentsRegistry::create();
$subagentStore = new SubagentStore($storage, new LockFactory(new SemaphoreStore()));
$adapter = new ConsoleAdapter(
    [\PHP_BINARY, __DIR__ . '/subagent-worker.php'],
    \dirname(__DIR__) . '/.storage/subagents/worker.log',
);
$printed = false;
$subagents = new Subagents(
    $subagentsRegistry,
    $subagentStore,
    $adapter,
    onEvent: static function (SubagentDispatched|SubagentCompleted $event) use (&$printed): void {
        $status = $event instanceof SubagentDispatched ? 'worker launched' : 'completed';
        echo \PHP_EOL . "[subagent {$event->run->agent} #{$event->run->id}] {$status}" . \PHP_EOL;
        if ($event instanceof SubagentCompleted && $event->recovered) {
            echo $event->reply->getContent() ?? '';
        }
        $printed = true;
        \flush();
    },
);

$conversation = new Conversation($agent, $session);
$conversation->setSubagents($subagents);
$conversation->setCommands(new Commands(new ExitCommand()));

echo 'Session: ' . $conversation->session()->getKey() . \PHP_EOL;
echo 'Try: Delegate a comparison of A (10 EUR/month) and B (80 EUR/year) to the general agent.' . \PHP_EOL;
echo 'With JINA_API_KEY, ask general to find the release date of PHP 8.4 and cite the official announcement.' . \PHP_EOL;
echo 'Wait after the first response: the assistant will report the result automatically.' . \PHP_EOL;
echo 'Use /exit to leave.' . \PHP_EOL;

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
            $onEvent = function (object $event) use (&$printed): void {
                if ($event instanceof ExitRequest) {
                    throw new ExecutionAbortedException();
                }

                if ($event instanceof TextChunk) {
                    $text = $event->content;
                } elseif ($event instanceof Notification) {
                    $text = $event->text . \PHP_EOL;
                } else {
                    return;
                }

                if ($text === '') {
                    return;
                }
                if (!$printed) {
                    echo \PHP_EOL;
                    $printed = true;
                }
                echo $text;
                \flush();
            };

            if ($input === null) {
                $conversation->runCompletions($onEvent);
            } else {
                $conversation->runInput($input, $onEvent);
            }

            if ($printed) {
                echo \PHP_EOL . \PHP_EOL;
            }
        } catch (ExecutionAbortedException) {
            break;
        } catch (Throwable $error) {
            \fwrite(\STDERR, \PHP_EOL . $error->getMessage() . \PHP_EOL);
            $printed = true;
            if ($input === null) {
                // Keep accepting input while waiting before retrying a failed completion.
                $nextCompletionCheck = \microtime(true) + 10;
            }
        }

        if ($printed) {
            echo '> ';
        }
    }
} finally {
    $stopReading->cancel();
    $reader->await();
}
