<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use NeuronAI\StructuredOutput\SchemaProperty;

/** @internal */
final class SessionTitleResult
{
    #[SchemaProperty(description: 'A short topic title, or null if no concrete topic has emerged.', required: true, maxLength: 80)]
    public ?string $title;
}
