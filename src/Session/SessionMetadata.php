<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use InvalidArgumentException;

use function is_string;
use function preg_match;

/** @internal Validates application metadata; it lives inside the Session value. */
final class SessionMetadata
{
    /**
     * @param array<array-key, mixed> $metadata
     * @return array<string, string>
     */
    public static function validate(array $metadata): array
    {
        $validated = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new InvalidArgumentException('Session metadata must contain camelCase string pairs.');
            }
            self::key($key);
            $validated[$key] = $value;
        }
        return $validated;
    }

    public static function key(string $key): string
    {
        if (preg_match('/^[a-z][a-zA-Z0-9]*$/D', $key) !== 1) {
            throw new InvalidArgumentException('Session metadata must contain camelCase string pairs.');
        }
        return $key;
    }
}
