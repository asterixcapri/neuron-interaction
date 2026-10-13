<?php

declare(strict_types=1);

namespace NeuronInteraction\Storage;

use InvalidArgumentException;

use function array_is_list;
use function array_key_exists;
use function array_shift;
use function array_slice;
use function count;
use function is_array;
use function is_string;
use function min;

/** Pure field update; adapters must hold their mutation lock around read/apply/write. */
final class StorageUpdate
{
    /**
     * @param array<array-key, mixed> $value
     * @param array<array-key, mixed> $assignments
     * @param array<array-key, mixed> $removals
     * @return array<array-key, mixed>
     */
    public static function apply(array $value, array $assignments, array $removals): array
    {
        if (!array_is_list($assignments) || !array_is_list($removals)) {
            throw new InvalidArgumentException('Storage update operations must be lists.');
        }
        $paths = [];
        $validatedAssignments = [];
        foreach ($assignments as $assignment) {
            if (!is_array($assignment) || !isset($assignment['path']) || !array_key_exists('value', $assignment) || count($assignment) !== 2) {
                throw new InvalidArgumentException('Assignments require exactly a path and value.');
            }
            $path = self::path($assignment['path']);
            $validatedAssignments[] = ['path' => $path, 'value' => StorageValue::field($assignment['value'])];
            $paths[] = $path;
        }
        $validatedRemovals = [];
        foreach ($removals as $path) {
            $validatedRemovals[] = $paths[] = self::path($path);
        }
        foreach ($paths as $index => $path) {
            foreach (array_slice($paths, $index + 1) as $other) {
                $length = min(count($path), count($other));
                if (array_slice($path, 0, $length) === array_slice($other, 0, $length)) {
                    throw new InvalidArgumentException('Storage update paths must not overlap.');
                }
            }
        }
        $result = StorageValue::snapshot($value);
        foreach ($validatedAssignments as $assignment) {
            $result = self::change($result, $assignment['path'], $assignment['value'], false);
        }
        foreach ($validatedRemovals as $path) {
            $result = self::change($result, $path, null, true);
        }

        return StorageValue::snapshot($result);
    }

    /** @return non-empty-list<string> */
    private static function path(mixed $path): array
    {
        if (!is_array($path) || $path === [] || !array_is_list($path)) {
            throw new InvalidArgumentException('Storage update paths must be non-empty lists.');
        }
        $segments = [];
        foreach ($path as $segment) {
            if (!is_string($segment)) {
                throw new InvalidArgumentException('Storage update path segments must be strings.');
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * @param array<array-key, mixed> $object
     * @param non-empty-list<string> $path
     * @return array<array-key, mixed>
     */
    private static function change(array $object, array $path, mixed $value, bool $remove): array
    {
        // PHP's empty array also represents an empty object. Non-empty lists
        // cannot be traversed, including at the root.
        if ($object !== [] && array_is_list($object)) {
            throw new InvalidArgumentException('Storage update cannot traverse a list.');
        }
        $segment = array_shift($path);
        if ($path === []) {
            if ($remove) {
                unset($object[$segment]);
            } else {
                $object[$segment] = StorageValue::field($value);
            }

            return $object;
        }
        if (!array_key_exists($segment, $object)) {
            if ($remove) {
                return $object;
            }
            $child = [];
        } else {
            $child = $object[$segment];
            if (!is_array($child)) {
                throw new InvalidArgumentException('Storage update cannot traverse a scalar.');
            }
        }
        $object[$segment] = self::change($child, $path, $value, $remove);

        return $object;
    }
}
