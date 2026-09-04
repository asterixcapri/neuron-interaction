<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Examples\BackendControls;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\Sessions;
use NeuronInteraction\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class BackendExampleTest extends TestCase
{
    public function testSelectionExampleSurvivesItsSerializedRoundTrip(): void
    {
        ob_start();

        try {
            require dirname(__DIR__, 2) . '/examples/backend.php';
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertIsString($output);
        self::assertStringContainsString('"command":"resume"', $output);
        self::assertStringEndsWith('A conversation to reopen' . PHP_EOL, $output);
    }

    public function testPromptingDelegatesToTheHostWithoutRecordingGeneratedInput(): void
    {
        $storage = new InMemoryStorage();
        $inputs = new InputHistory($storage);
        $inputs->record('summarize');
        $agent = new Agent();
        $received = [];
        $controls = new BackendControls(
            $agent,
            new Commands(),
            new Sessions($storage),
            static function (Agent $answering, string $prompt) use (&$received): void {
                $received[] = [$answering, $prompt];
            },
        );

        $controls->promptAgent('A generated summary prompt');

        self::assertSame([[$agent, 'A generated summary prompt']], $received);
        self::assertSame(['summarize'], $inputs->entries());
    }
}
