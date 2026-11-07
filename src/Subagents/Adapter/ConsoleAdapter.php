<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Adapter;

use InvalidArgumentException;
use NeuronInteraction\Subagents\BackgroundAdapterInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

use function array_map;
use function ctype_digit;
use function escapeshellarg;
use function getenv;
use function implode;
use function trim;

use const DIRECTORY_SEPARATOR;

/** POSIX detached worker. The command prefix is trusted application configuration. */
final readonly class ConsoleAdapter implements BackgroundAdapterInterface
{
    /** @param list<string> $command e.g. [PHP_BINARY, '/app/examples/Console/worker.php'] */
    public function __construct(private array $command, private string $logFile = '/dev/null')
    {
        if ($command === [] || DIRECTORY_SEPARATOR !== '/') {
            throw new InvalidArgumentException('Console adapter requires a command and a POSIX host.');
        }
    }

    public function dispatch(string $runId): void
    {
        $command = implode(' ', array_map('escapeshellarg', [...$this->command, 'subagents:work', $runId]));
        $launcher = new Process(['/bin/sh', '-c',
            'nohup ' . $command . ' </dev/null >>' . escapeshellarg($this->logFile) . ' 2>&1 & echo $!'], env: getenv());
        $launcher->setTimeout(5);
        $launcher->mustRun();
        if (!ctype_digit(trim($launcher->getOutput()))) {
            throw new RuntimeException('Worker could not be launched.');
        }
    }
}
