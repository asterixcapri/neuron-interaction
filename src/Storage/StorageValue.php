<?php

declare(strict_types=1);

namespace NeuronInteraction\Storage;

use InvalidArgumentException;
use JsonException;

use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function preg_match;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

/** Shared validation and detached snapshots for both entry adapters. */
final class StorageValue
{
    public static function identifier(string $identifier): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('The storage identifier is not portable.');
        }
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public static function snapshot(array $value): array
    {
        try {
            // Check recursion and invalid UTF-8 before recursively copying fields.
            json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Storage values must be JSON-compatible.', previous: $exception);
        }

        $snapshot = [];
        foreach ($value as $key => $field) {
            $snapshot[$key] = self::field($field);
        }

        return $snapshot;
    }

    public static function field(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::snapshot($value);
        }
        if ($value === null || is_string($value) || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            if (is_string($value)) {
                try {
                    json_encode($value, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException('Storage strings must be valid UTF-8.', previous: $exception);
                }
            }

            return $value;
        }

        throw new InvalidArgumentException('Storage values must contain only JSON scalars and arrays.');
    }
}
