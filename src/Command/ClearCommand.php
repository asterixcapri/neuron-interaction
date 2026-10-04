<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use NeuronInteraction\Session\SessionStore;

/**
 * Starts a new Session, leaving the previous one where it is stored.
 *
 * A Host Application registers it under `clear` or a name of its own.
 *
 * Starting a Session binds an Agent copy to a new conversation.
 * Nothing here deletes the conversation the new Session replaced.
 */
final readonly class ClearCommand implements CommandInterface
{
    /** @param string $name the presentation-neutral identifier */
    public function __construct(private SessionStore $sessionStore, private string $name = '/clear') {}

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Starts a new Session, leaving the current one stored.';
    }

    public function run(CommandContext $context, string $value): void
    {
        $session = $this->sessionStore->create();
        $context->useSession($session);
    }
}
