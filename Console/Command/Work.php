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
    public function __construct(
        private readonly Worker $worker,
        private readonly State $state,
        private readonly Queue $queue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(name: 'agent001:work')->setDescription(description: 'Process the durable Agent001 queue.');
        $this->addOption(
            name: 'retry-failed',
            mode: InputOption::VALUE_NONE,
            description: 'Retry retained failed jobs.',
        );
        $this->addOption(name: 'once', mode: InputOption::VALUE_NONE, description: 'Process one page and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->getAreaCode();
        } catch (LocalizedException) {
            $this->state->setAreaCode(code: 'crontab');
        }
        if ($input->getOption(name: 'retry-failed')) {
            $this->queue->retryFailed();
        }
        $once = (bool) $input->getOption(name: 'once');
        do {
            $count = $this->worker->run();
            if (!$once) {
                sleep(seconds: $count ? 1 : 5);
            }
        } while (!$once);
        $output->writeln(messages: 'Agent001 queue page processed.');
        return Command::SUCCESS;
    }
}
