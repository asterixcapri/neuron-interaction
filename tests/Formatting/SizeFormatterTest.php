<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Formatting;

use Generator;
use NeuronInteraction\Formatting\SizeFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SizeFormatterTest extends TestCase
{
    #[DataProvider('sizes')]
    public function testItFormatsBytes(int $bytes, string $expected): void
    {
        self::assertSame($expected, SizeFormatter::format($bytes));
    }

    /** @return Generator<string, array{int, string}> */
    public static function sizes(): Generator
    {
        yield 'zero' => [0, '0B'];
        yield 'bytes' => [999, '999B'];
        yield 'below kilobyte' => [1_023, '1023B'];
        yield 'kilobyte' => [1_024, '1KB'];
        yield 'fractional kilobytes' => [30_515, '29.8KB'];
        yield 'megabyte' => [1_048_576, '1MB'];
        yield 'fractional megabytes' => [1_572_864, '1.5MB'];
        yield 'gigabyte' => [1_073_741_824, '1GB'];
        yield 'fractional gigabytes' => [1_610_612_736, '1.5GB'];
        yield 'terabyte' => [1_099_511_627_776, '1TB'];
        yield 'petabyte' => [1_125_899_906_842_624, '1PB'];
        yield 'exabyte' => [1_152_921_504_606_846_976, '1EB'];
    }
}
