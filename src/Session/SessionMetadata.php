<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use InvalidArgumentException;

/** @internal Encodes application metadata separately from Session system fields. */
final class SessionMetadata
{
    /**
     * @param array<array-key, mixed> $metadata
     * @return array<string, string>
     */
    public static function encode(array $metadata): array
    {
        $encoded = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new InvalidArgumentException('Session metadata must contain camelCase string pairs.');
            }
            $encoded[self::key($key)] = $value;
        }

        return $encoded;
    }

    public static function key(string $key): string
    {
        if (preg_match('/^[a-z][a-zA-Z0-9]*$/D', $key) !== 1) {
            throw new InvalidArgumentException('Session metadata must contain camelCase string pairs.');
        }

        return 'app' . ucfirst($key);
    }

    /**
     * @param array<string, string> $metadata
     * @return array<string, string>
     */
    public static function decode(array $metadata): array
    {
        $decoded = [];
        foreach ($metadata as $key => $value) {
            if (str_starts_with($key, 'app')) {
                $decoded[lcfirst(substr($key, 3))] = $value;
            }
        }

        return $decoded;
    }
}
