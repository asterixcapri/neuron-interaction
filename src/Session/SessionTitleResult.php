<?php

declare(strict_types=1);

namespace NeuronInteraction\Session;

use NeuronAI\StructuredOutput\SchemaProperty;

/** @internal */
final class SessionTitleResult
{
    #[SchemaProperty(description: 'A concise title of 2–5 words for a meaningful subject expressed by the user, or null for small talk or unclear intent.', required: true)]
    public ?string $title;
}
