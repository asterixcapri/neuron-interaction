<?php

declare(strict_types=1);

namespace NeuronInteraction\Storage;

use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

use function array_map;
use function array_slice;
use function basename;
use function bin2hex;
use function clearstatcache;
use function dirname;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function fstat;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function json_decode;
use function json_encode;
use function link;
use function lstat;
use function mkdir;
use function random_bytes;
use function realpath;
use function rename;
use function strlen;
use function substr;
use function tempnam;
use function unlink;
use function usort;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;
use const PHP_INT_MAX;

/** One complete JSON document per entry, coordinated by a stable namespace lock. */
final class FileStorage implements StorageInterface
{
    public function __construct(private readonly string $root) {}

    public function create(string $namespace, array $value, ?string $key = null): string
    {
        StorageValue::identifier($namespace);
        if ($key !== null) {
            StorageValue::identifier($key);
        }
        $value = StorageValue::snapshot($value);
        return $this->locked($namespace, true, true, function (string $directory) use ($value, $key): string {
            do {
                $candidate = $key ?? bin2hex(random_bytes(16));
                $path = $this->path($directory, $candidate);
                $exists = $this->regularFile($path);
                if ($exists && $key !== null) {
                    throw new DuplicateKeyException('The storage key already exists.');
                }
            } while ($exists);
            $position = $this->reservePosition($directory);
            $this->publish($path, ['position' => $position, 'value' => $value], true);
            return $candidate;
        }) ?? throw new RuntimeException('The storage namespace could not be created.');
    }

    public function get(string $namespace, string $key): ?array
    {
        $this->identifiers($namespace, $key);
        return $this->locked($namespace, false, false, function (string $directory) use ($key): ?array {
            return $this->readEntry($this->path($directory, $key))['value'] ?? null;
        });
    }

    public function set(string $namespace, string $key, array $value): void
    {
        $this->identifiers($namespace, $key);
        $value = StorageValue::snapshot($value);
        $this->locked($namespace, true, true, function (string $directory) use ($key, $value): void {
            $path = $this->path($directory, $key);
            $entry = $this->readEntry($path);
            $position = $entry['position'] ?? $this->reservePosition($directory);
            $this->publish($path, ['position' => $position, 'value' => $value]);
        });
    }

    public function update(string $namespace, string $key, array $assignments = [], array $removals = []): void
    {
        $this->identifiers($namespace, $key);
        $directory = $this->directory($namespace, false);
        if ($directory === null) {
            throw new MissingEntryException('The storage entry does not exist.');
        }
        $this->locked($namespace, false, true, function (string $directory) use ($key, $assignments, $removals): void {
            $path = $this->path($directory, $key);
            $entry = $this->readEntry($path);
            if ($entry === null) {
                throw new MissingEntryException('The storage entry does not exist.');
            }
            $value = StorageUpdate::apply($entry['value'], $assignments, $removals);
            if ($assignments !== [] || $removals !== []) {
                $this->publish($path, ['position' => $entry['position'], 'value' => $value]);
            }
        });
    }

    public function delete(string $namespace, string $key): void
    {
        $this->identifiers($namespace, $key);
        $this->locked($namespace, false, true, function (string $directory) use ($key): void {
            $path = $this->path($directory, $key);
            if ($this->regularFile($path) && !unlink($path)) {
                throw new RuntimeException('The storage entry could not be deleted.');
            }
        });
    }

    public function list(string $namespace, ?int $limit = null): array
    {
        StorageValue::identifier($namespace);
        if ($limit !== null && $limit < 0) {
            throw new InvalidArgumentException('The storage list limit cannot be negative.');
        }
        return $this->locked($namespace, false, false, function (string $directory) use ($limit): array {
            $entries = [];
            foreach ($this->entryPaths($directory) as $path) {
                $key = substr(basename($path), 0, -5);
                StorageValue::identifier($key);
                $entry = $this->readEntry($path);
                if ($entry === null) {
                    throw new RuntimeException('A listed storage entry disappeared.');
                }
                $entries[] = ['key' => $key, 'value' => $entry['value'], 'position' => $entry['position']];
            }
            usort($entries, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
            if ($limit !== null) {
                $entries = $limit === 0 ? [] : array_slice($entries, -$limit);
            }
            return array_map(static fn(array $entry): array => ['key' => $entry['key'], 'value' => $entry['value']], $entries);
        }, []) ?? [];
    }

    public function clear(string $namespace): void
    {
        StorageValue::identifier($namespace);
        $this->locked($namespace, false, true, function (string $directory): void {
            $paths = $this->entryPaths($directory);
            foreach ($paths as $path) {
                $this->regularFile($path);
            }
            foreach ($paths as $path) {
                if (!unlink($path)) {
                    throw new RuntimeException('The storage namespace could not be cleared.');
                }
            }
        });
    }

    /**
     * @template T
     * @param Closure(string): T $operation
     * @param T|null $missing
     * @return T|null
     */
    private function locked(string $namespace, bool $create, bool $exclusive, Closure $operation, mixed $missing = null): mixed
    {
        $directory = $this->directory($namespace, $create);
        if ($directory === null) {
            return $missing;
        }
        $path = $directory . '/.lock';
        $this->regularFile($path);
        $lock = @fopen($path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('The namespace lock could not be opened.');
        }
        try {
            $opened = fstat($lock);
            clearstatcache(true, $path);
            $actual = lstat($path);
            if (!$this->regularFile($path) || $opened === false || $actual === false || $opened['ino'] !== $actual['ino'] || $opened['dev'] !== $actual['dev']) {
                throw new RuntimeException('The namespace lock was substituted.');
            }
            if (!flock($lock, $exclusive ? LOCK_EX : LOCK_SH)) {
                throw new RuntimeException('The namespace lock could not be acquired.');
            }
            clearstatcache(true, $path);
            $actual = lstat($path);
            if (!$this->regularFile($path) || $actual === false || $opened['ino'] !== $actual['ino'] || $opened['dev'] !== $actual['dev']) {
                throw new RuntimeException('The namespace lock was substituted while waiting.');
            }
            return $operation($directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function directory(string $namespace, bool $create): ?string
    {
        clearstatcache();
        if (!file_exists($this->root)) {
            if (!$create) {
                return null;
            }
            if (!@mkdir($this->root, 0o777, true) && !is_dir($this->root)) {
                throw new RuntimeException('The storage root could not be created.');
            }
        }
        $root = realpath($this->root);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('The storage root must be a directory.');
        }
        $directory = $root . '/' . $namespace;
        if (is_link($directory)) {
            throw new RuntimeException('A storage namespace cannot be a symbolic link.');
        }
        if (!file_exists($directory)) {
            if (!$create) {
                return null;
            }
            if (!@mkdir($directory) && !is_dir($directory)) {
                throw new RuntimeException('The storage namespace could not be created.');
            }
        }
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved) || dirname($resolved) !== $root) {
            throw new RuntimeException('The storage namespace is outside the configured root.');
        }
        return $resolved;
    }

    private function identifiers(string $namespace, string $key): void
    {
        StorageValue::identifier($namespace);
        StorageValue::identifier($key);
    }

    private function path(string $directory, string $key): string
    {
        return $directory . '/' . $key . '.json';
    }

    /** Filesystem state may change between calls from other processes.
     * @phpstan-impure
     */
    private function regularFile(string $path): bool
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('A storage file cannot be a symbolic link.');
        }
        if (!file_exists($path)) {
            return false;
        }
        if (!is_file($path)) {
            throw new RuntimeException('A storage file must be a regular file.');
        }
        return true;
    }

    /** @return array{position: positive-int, value: array<array-key, mixed>}|null */
    private function readEntry(string $path): ?array
    {
        if (!$this->regularFile($path)) {
            return null;
        }
        $encoded = @file_get_contents($path);
        if ($encoded === false) {
            throw new RuntimeException('The storage entry could not be read.');
        }
        try {
            $entry = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($entry) || !isset($entry['position'], $entry['value']) || !is_int($entry['position']) || $entry['position'] < 1 || !is_array($entry['value'])) {
                throw new UnexpectedValueException('The stored entry has an invalid envelope.');
            }
            return ['position' => $entry['position'], 'value' => StorageValue::snapshot($entry['value'])];
        } catch (JsonException|InvalidArgumentException $exception) {
            throw new UnexpectedValueException('The stored entry contains invalid JSON data.', previous: $exception);
        }
    }

    /** @return list<string> */
    private function entryPaths(string $directory): array
    {
        $paths = glob($directory . '/*.json');
        if ($paths === false) {
            throw new RuntimeException('The storage namespace could not be listed.');
        }
        return $paths;
    }

    private function reservePosition(string $directory): int
    {
        $path = $directory . '/.position';
        $position = 0;
        if ($this->regularFile($path)) {
            $encoded = file_get_contents($path);
            if ($encoded === false) {
                throw new RuntimeException('The namespace counter could not be read.');
            }
            try {
                $position = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new UnexpectedValueException('The namespace counter is corrupt.', previous: $exception);
            }
            if (!is_int($position) || $position < 0) {
                throw new UnexpectedValueException('The namespace counter is invalid.');
            }
        }
        if ($position === PHP_INT_MAX) {
            throw new RuntimeException('The namespace insertion positions are exhausted.');
        }
        ++$position;
        $this->publish($path, $position);
        return $position;
    }

    /** @param array{position: int, value: array<array-key, mixed>}|int $document */
    private function publish(string $path, array|int $document, bool $create = false): void
    {
        $this->regularFile($path);
        $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $temporary = tempnam(dirname($path), '.entry-');
        if ($temporary === false) {
            throw new RuntimeException('A temporary storage file could not be created.');
        }
        try {
            if (dirname($temporary) !== dirname($path)) {
                throw new RuntimeException('The temporary document is outside the namespace.');
            }
            $written = file_put_contents($temporary, $encoded);
            if ($written !== strlen($encoded)) {
                throw new RuntimeException('The complete storage document could not be written.');
            }
            $this->regularFile($path);
            $published = $create ? @link($temporary, $path) : @rename($temporary, $path);
            if (!$published) {
                throw new RuntimeException('The storage document could not be published.');
            }
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }
}
