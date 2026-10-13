<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use DateTimeImmutable;
use UnexpectedValueException;

use function is_array;
use function is_bool;
use function is_string;

/** @internal A validated immutable snapshot, with no persistence operations. */
final readonly class SessionRecord
{
    /** @param array<string, string> $metadata */
    private function __construct(
        public ?string $title,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastUsedAt,
        public bool $hasContent,
        public array $metadata,
    ) {}

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        $title = $value['title'] ?? null;
        if ($title !== null && !is_string($title)) {
            throw new UnexpectedValueException('A Session title must be a string or null.');
        }
        $content = $value['hasContent'] ?? null;
        if (!is_bool($content)) {
            throw new UnexpectedValueException('A Session record must contain a boolean content flag.');
        }
        $metadata = $value['metadata'] ?? null;
        if (!is_array($metadata)) {
            throw new UnexpectedValueException('A Session record must contain application metadata.');
        }
        return new self($title, self::date($value, 'createdAt'), self::date($value, 'lastUsedAt'), $content, SessionMetadata::validate($metadata));
    }

    /** @param array<array-key, mixed> $record */
    private static function date(array $record, string $field): DateTimeImmutable
    {
        $value = $record[$field] ?? null;
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $value) : false;
        if ($date === false || $date->format('Y-m-d\TH:i:s.uP') !== $value) {
            throw new UnexpectedValueException('A Session record must contain a valid ' . $field . ' time.');
        }
        return $date;
    }
}
