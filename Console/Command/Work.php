<?php

declare(strict_types=1);

namespace Agent001\Assistant\Console\Command;

use Agent001\Assistant\Model\Sync\Worker;
use Agent001\Assistant\Model\Sync\Queue;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Work extends Command
{
    private const MEMORY_LIMIT = 536870912;

    public function __construct(
        private readonly Worker $worker,
        private readonly State $state,
        private readonly Queue $queue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('agent001:work')->setDescription('Process the durable Agent001 queue.');
        $this->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Retry retained failed jobs.');
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Process one page and exit.');
        $this->addOption(
            'max-time',
            null,
            InputOption::VALUE_REQUIRED,
            'Exit after this many seconds so the process manager restarts the worker.',
            '3600',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->getAreaCode();
        } catch (LocalizedException) {
            $this->state->setAreaCode('crontab');
        }
        if ($input->getOption('retry-failed')) {
            $this->queue->retryFailed();
        }
        $once = (bool) $input->getOption('once');
        $deadline = time() + max(1, (int) $input->getOption('max-time'));
        do {
            $count = $this->worker->run();
            $expired = time() >= $deadline || memory_get_usage(real_usage: true) > self::MEMORY_LIMIT;
            if (!$once && !$expired) {
                sleep(seconds: $count ? 1 : 5);
            }
        } while (!$once && !$expired);
        $output->writeln('Agent001 queue page processed.');
        return Command::SUCCESS;
    }
}
