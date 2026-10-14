<?php

declare(strict_types=1);

namespace NeuronInteraction\Storage;

use InvalidArgumentException;
use JsonException;

use function array_is_list;
use function array_key_exists;
use function array_shift;
use function array_slice;
use function bin2hex;
use function count;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function min;
use function preg_match;
use function random_bytes;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

/** Shared entry rules; concrete storages own persistence and atomicity. */
abstract class AbstractStorage implements StorageInterface
{
    protected function validateIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('The storage identifier is not portable.');
        }
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    protected function snapshotValue(array $value): array
    {
        try {
            // Check recursion and invalid UTF-8 before recursively copying fields.
            json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Storage values must be JSON-compatible.', previous: $exception);
        }

        $snapshot = [];
        foreach ($value as $key => $field) {
            $snapshot[$key] = $this->snapshotField($field);
        }

        return $snapshot;
    }

    private function snapshotField(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->snapshotValue($value);
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

    /**
     * @param array<array-key, mixed> $value
     * @param array<array-key, mixed> $assignments
     * @param array<array-key, mixed> $removals
     * @return array<array-key, mixed>
     */
    protected function applyUpdate(array $value, array $assignments, array $removals): array
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
            $path = $this->updatePath($assignment['path']);
            $validatedAssignments[] = ['path' => $path, 'value' => $this->snapshotField($assignment['value'])];
            $paths[] = $path;
        }
        $validatedRemovals = [];
        foreach ($removals as $path) {
            $validatedRemovals[] = $paths[] = $this->updatePath($path);
        }
        foreach ($paths as $index => $path) {
            foreach (array_slice($paths, $index + 1) as $other) {
                $length = min(count($path), count($other));
                if (array_slice($path, 0, $length) === array_slice($other, 0, $length)) {
                    throw new InvalidArgumentException('Storage update paths must not overlap.');
                }
            }
        }
        $result = $this->snapshotValue($value);
        foreach ($validatedAssignments as $assignment) {
            $result = $this->changeField($result, $assignment['path'], $assignment['value'], false);
        }
        foreach ($validatedRemovals as $path) {
            $result = $this->changeField($result, $path, null, true);
        }

        return $this->snapshotValue($result);
    }

    /** @return non-empty-list<string> */
    private function updatePath(mixed $path): array
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
    private function changeField(array $object, array $path, mixed $value, bool $remove): array
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
                $object[$segment] = $this->snapshotField($value);
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
        $object[$segment] = $this->changeField($child, $path, $value, $remove);

        return $object;
    }

    protected function validateIdentifiers(string $namespace, string $key): void
    {
        $this->validateIdentifier($namespace);
        $this->validateIdentifier($key);
    }

    /** Generate a candidate; the storage checks uniqueness during insertion. */
    protected function generateKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
