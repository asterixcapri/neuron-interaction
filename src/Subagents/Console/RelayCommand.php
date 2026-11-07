<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Console;

use InvalidArgumentException;
use NeuronInteraction\Subagents\Adapter\MessengerCompletionRelay;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

use function filter_var;
use function sleep;

use const FILTER_VALIDATE_INT;

#[AsCommand(name: 'subagents:relay', description: 'Schedule parent turns for pending subagent completions.')]
final class RelayCommand extends Command
{
    public function __construct(private readonly MessengerCompletionRelay $relay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('watch', null, InputOption::VALUE_NONE, 'Repeat until stopped; run under supervision.')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between relay passes.', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = filter_var($input->getOption('interval'), FILTER_VALIDATE_INT);
        if ($interval === false || $interval < 1) {
            throw new InvalidArgumentException('Interval must be a positive integer.');
        }
        do {
            $count = $this->relay->dispatchPending();
            if ($count > 0) {
                $output->writeln("Scheduled {$count} conversation(s).");
            }
            if (!$input->getOption('watch')) {
                break;
            }
            sleep($interval);
        } while (true);
        return Command::SUCCESS;
    }
}
