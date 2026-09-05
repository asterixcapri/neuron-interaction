<?php

declare(strict_types=1);

namespace NeuronInteraction\Command;

use DateTimeImmutable;

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
            $adapter->agent()->setChatHistory(
                $adapter->sessions()->resume($arguments->text),
            );

            return;
        }

        $sessions = $adapter->sessions()->list();

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
                SessionMetadata::format($session, $now),
            );
        }

        $adapter->requestSelection(new SelectionRequest($this->name(), 'Sessions', $options));
    }
}
