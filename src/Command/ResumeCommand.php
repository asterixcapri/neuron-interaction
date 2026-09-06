<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use DateTimeImmutable;
use NeuronInteraction\Formatting\RelativeTimeFormatter;
use NeuronInteraction\Formatting\SizeFormatter;
use NeuronInteraction\Session\SessionSummary;

/**
 * Offers the stored Sessions so a person can resume one.
 *
 * A Host Application mounts it under `resume` or a name of its own.
 *
 * A list with nothing in it is not worth entering, so it is said in the
 * conversation instead. The Sessions become Selection options here, while their
 * keys and titles are still something known.
 */
final readonly class ResumeCommand implements CommandInterface
{
    /** @param string $name the presentation-neutral identifier */
    public function __construct(private string $name = '/resume')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Lets you choose a stored Session to resume.';
    }

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
    {
        if ($arguments->text !== '') {
            $session = $adapter->sessionStore()->read($arguments->text);

            if ($session === null) {
                $adapter->warn('No Session is named by that key.');

                return;
            }

            $adapter->useSession($session);

            return;
        }

        $sessions = $adapter->sessionStore()->summaries();

        if ($sessions === []) {
            $adapter->warn('There is no earlier Session to return to yet.');

            return;
        }

        $options = [];
        $now = new DateTimeImmutable();

        foreach ($sessions as $session) {
            $options[] = new SelectionOption(
                $session->key,
                $session->title,
                $this->formatDescription($session, $now),
            );
        }

        $adapter->requestSelection(new SelectionRequest($this->name(), 'Sessions', $options));
    }

    private function formatDescription(
        SessionSummary $session,
        DateTimeImmutable $now,
    ): string {
        $relativeAge = RelativeTimeFormatter::format($session->lastUsedAt, $now);

        if ($session->size === null) {
            return $relativeAge;
        }

        return $relativeAge . ' · ' . SizeFormatter::format($session->size);
    }
}
