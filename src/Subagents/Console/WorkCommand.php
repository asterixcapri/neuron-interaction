<?php

declare(strict_types=1);

namespace NeuronInteraction\Subagents\Console;

use InvalidArgumentException;
use NeuronInteraction\Subagents\Executor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class WorkCommand extends Command
{
    public function __construct(private readonly Executor $executor)
    {
        parent::__construct('subagents:work');
    }

    protected function configure(): void
    {
        $this->setDescription('Execute a persisted subagent run.')->addArgument('run-id', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('run-id');
        if (!is_string($id)) {
            throw new InvalidArgumentException('A run ID is required.');
        }
        $run = $this->executor->execute($id);
        $output->writeln(json_encode($run->summary(), JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }
}
