<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Storage;

use RuntimeException;

use function dirname;
use function fclose;
use function fgets;
use function fwrite;
use function is_array;
use function is_resource;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function stream_set_timeout;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/** Pipe-coordinated worker exercising only the public storage contract. */
final class StorageWorker
{
    /** @var resource */
    private $process;
    /** @var array<int, resource> */
    private array $pipes;

    public function __construct(string $root)
    {
        $script = <<<'PHP'
            require $argv[1];
            $storage = new NeuronInteraction\Storage\FileStorage($argv[2]);
            echo "ready\n";
            flush();
            while (($line = fgets(STDIN)) !== false) {
                $command = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                try {
                    $result = match ($command['operation']) {
                        'create' => $storage->create('items', $command['value'], $command['key']),
                        'set' => $storage->set('items', $command['key'], $command['value']),
                        'update' => $storage->update('items', $command['key'], $command['assignments']),
                        'get' => $storage->get('items', $command['key']),
                        'list' => $storage->list('items'),
                        'delete' => $storage->delete('items', $command['key']),
                        'clear' => $storage->clear('items'),
                    };
                    echo json_encode(['result' => $result], JSON_THROW_ON_ERROR) . "\n";
                } catch (NeuronInteraction\Storage\DuplicateKeyException) {
                    echo json_encode(['conflict' => true], JSON_THROW_ON_ERROR) . "\n";
                }
                flush();
            }
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $root],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The worker could not be started.');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_timeout($this->pipes[1], 15);
        if (fgets($this->pipes[1]) !== "ready\n") {
            throw new RuntimeException('The worker did not acknowledge readiness.');
        }
    }

    /** @param array<string, mixed> $command */
    public function send(array $command): void
    {
        $encoded = json_encode($command, JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($this->pipes[0], $encoded) !== strlen($encoded)) {
            throw new RuntimeException('The command could not be sent to the worker.');
        }
    }

    /** @return array<array-key, mixed> */
    public function receive(): array
    {
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            throw new RuntimeException('The worker did not acknowledge its operation.');
        }
        $response = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($response)) {
            throw new RuntimeException('The worker returned an invalid response.');
        }
        return $response;
    }

    public function close(): void
    {
        fclose($this->pipes[0]);
        $output = stream_get_contents($this->pipes[1]);
        $error = stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        $status = proc_close($this->process);
        if ($status !== 0 || $error !== '' || $output !== '') {
            throw new RuntimeException('The storage worker failed: ' . $error);
        }
    }
}
