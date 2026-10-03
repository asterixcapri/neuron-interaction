<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use DateTimeImmutable;
use NeuronInteraction\Formatting\RelativeTimeFormatter;
use NeuronInteraction\Formatting\SizeFormatter;
use NeuronInteraction\Session\SessionSummary;

use function trim;

/**
 * Offers the stored Sessions so a person can resume one.
 *
 * A Host Application registers it under `resume` or a name of its own.
 *
 * A list with nothing in it is not worth entering, so it is said in the
 * conversation instead. The Sessions become Selection options here, while their
 * keys and titles are still something known.
 */
final readonly class ResumeCommand implements CommandInterface
{
    /** @param string $name the presentation-neutral identifier */
    public function __construct(private string $name = '/resume') {}

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Lets you choose a stored Session to resume.';
    }

    public function run(CommandContext $context, string $value): void
    {
        $value = trim($value);
        if ($value !== '') {
            $session = $context->sessionStore()->get($value);

            if ($session === null) {
                $context->notify('No Session is named by that key.', NotificationLevel::Error);

                return;
            }

            $context->useSession($session);

            return;
        }

        $sessions = $context->sessionStore()->list();

        if ($sessions === []) {
            $context->notify('There is no earlier Session to return to yet.', NotificationLevel::Warning);

            return;
        }

        $options = [];
        $now = new DateTimeImmutable();

        foreach ($sessions as $session) {
            $options[] = new SelectionOption(
                $session->getKey(),
                $session->getTitle() ?? 'New session',
                $this->formatDescription($session, $now),
            );
        }

        $context->requestSelection(new SelectionRequest($this->name(), 'Sessions', $options));
    }

    private function formatDescription(
        SessionSummary $session,
        DateTimeImmutable $now,
    ): string {
        $relativeAge = RelativeTimeFormatter::format($session->getLastUsedAt(), $now);

        $size = $session->getSize();
        if ($size === null) {
            return $relativeAge;
        }

        return $relativeAge . ' · ' . SizeFormatter::format($size);
    }
}
