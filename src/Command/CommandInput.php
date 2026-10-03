<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use InvalidArgumentException;

use function preg_match;

/** A Command invocation with an opaque argument value. */
final readonly class CommandInput
{
    public function __construct(public string $identifier, public string $value = '')
    {
        if (!self::isIdentifier($identifier)) {
            throw new InvalidArgumentException('Invalid Command identifier: ' . $identifier);
        }
    }

    public static function isIdentifier(string $identifier): bool
    {
        return preg_match('~^/[A-Za-z0-9_-]+$~D', $identifier) === 1;
    }

    public static function parse(string $line): ?self
    {
        if (preg_match('~^(/[A-Za-z0-9_-]+)(?:[ \t](.*))?$~sD', $line, $matches) !== 1) {
            return null;
        }
        return new self($matches[1], $matches[2] ?? '');
    }
}
