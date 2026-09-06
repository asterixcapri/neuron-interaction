<?php

declare(strict_types=1);

namespace NeuronInteraction\Formatting;

/** Formats bytes using powers of 1024 and at most one decimal place. */
final class SizeFormatter
{
    public static function format(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1_024 && $unit < count($units) - 1) {
            $size /= 1_024;
            ++$unit;
        }

        $amount = rtrim(
            rtrim(number_format($size, 1, '.', ''), '0'),
            '.',
        );

        return $amount . $units[$unit];
    }
}
