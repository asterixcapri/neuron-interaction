<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Subagents;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

use function bin2hex;
use function dirname;
use function extension_loaded;
use function file_get_contents;
use function is_file;
use function random_bytes;
use function str_contains;
use function str_replace;
use function substr_count;
use function sys_get_temp_dir;
use function var_export;

use const PHP_BINARY;

final class TerminalExampleTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function exits(): iterable
    {
        yield 'exit command with stdin still open' => [false];
        yield 'EOF with queued input' => [true];
    }

    #[DataProvider('exits')]
    public function testInputRemainsAvailableAndQueuedTurnsFinishInOrder(bool $eof): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/examples/vendor/autoload.php') || !extension_loaded('sysvsem')) {
            self::markTestSkipped('Install example dependencies and ext-sysvsem to exercise the terminal.');
        }
        $directory = sys_get_temp_dir() . '/interaction-terminal-' . bin2hex(random_bytes(8));
        $files = new Filesystem();
        $process = null;
        try {
            $files->mkdir([$directory . '/bin', $directory . '/vendor']);
            $files->dumpFile($directory . '/.env', '');
            $files->dumpFile($directory . '/vendor/autoload.php', '<?php require ' . var_export($root . '/examples/vendor/autoload.php', true) . ';');
            $source = file_get_contents($root . '/examples/bin/11-subagents.php');
            self::assertIsString($source);
            $provider = '$agent->setAiProvider(AIProviderFactory::create(\'openai:gpt-5.4-nano\'));';
            self::assertStringContainsString($provider, $source);
            // Observe the actual queue operation: the display prompt is only redrawn after a reply.
            $enqueue = '$inputs->enqueue($input);';
            self::assertStringContainsString($enqueue, $source);
            $source = str_replace($enqueue, $enqueue . ' echo "INPUT_QUEUED\n";', $source);
            // Execute the actual example loop with a controlled provider.
            $files->dumpFile($directory . '/bin/example.php', str_replace($provider, '$agent->setAiProvider($testProvider);', $source));
            $input = new InputStream();
            $release = $directory . '/release-provider';
            $process = new Process([PHP_BINARY, __DIR__ . '/terminal.php', $directory . '/bin/example.php', $release]);
            $process->setTimeout(5);
            $process->setInput($input);
            $process->start();
            self::assertTrue($process->waitUntil(static fn(): bool => str_contains($process->getOutput(), '> ')));
            $input->write("first\n");
            self::assertTrue($process->waitUntil(static fn(): bool => substr_count($process->getOutput(), 'INPUT_QUEUED') >= 1));
            self::assertStringNotContainsString('FIRST_ANSWER', $process->getOutput());
            $output = $process->getOutput();
            if (!str_contains($output, 'TURN_STARTED')) {
                self::assertTrue($process->waitUntil(static function (string $type, string $chunk) use (&$output): bool {
                    if ($type === Process::OUT) {
                        $output .= $chunk;
                    }
                    return str_contains($output, 'TURN_STARTED');
                }));
            }
            $input->write("second\n");
            self::assertTrue($process->waitUntil(static fn(): bool => substr_count($process->getOutput(), 'INPUT_QUEUED') >= 2));
            self::assertStringNotContainsString('FIRST_ANSWER', $process->getOutput());
            if ($eof) {
                $input->close();
            } else {
                $input->write("/exit\n");
                self::assertTrue($process->waitUntil(static fn(): bool => substr_count($process->getOutput(), 'INPUT_QUEUED') >= 3));
            }
            // Both user messages were read while the first model response was suspended.
            $files->dumpFile($release, '');
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertSame('', $process->getErrorOutput());
            self::assertSame(1, substr_count($process->getOutput(), 'FIRST_ANSWER'));
            self::assertSame(1, substr_count($process->getOutput(), 'SECOND_ANSWER'));
            self::assertMatchesRegularExpression('/FIRST_ANSWER.*SECOND_ANSWER/s', $process->getOutput());
        } finally {
            $process?->stop(0);
            $files->remove($directory);
        }
    }
}
