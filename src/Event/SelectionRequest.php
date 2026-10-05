<?php

declare(strict_types=1);

namespace NeuronInteraction\Event;

use InvalidArgumentException;
use NeuronInteraction\Command\SelectionOption;

use function array_is_list;

/** A choice the host presents before submitting another CommandInput. */
final readonly class SelectionRequest implements EventInterface
{
    /** @var non-empty-list<SelectionOption> */
    public array $options;

    /** @param array<array-key, SelectionOption> $options */
    public function __construct(
        public string $command,
        public string $prompt,
        array $options,
        public ?string $description = null,
    ) {
        if ($options === [] || !array_is_list($options)) {
            throw new InvalidArgumentException('A selection must offer an ordered non-empty list of options.');
        }

        $this->options = $options;
    }
}
