<?php

declare(strict_types=1);

namespace Agent001\Assistant\Console\Command;

use Agent001\Assistant\Model\Sync\Scheduler;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Sync extends Command
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly StoreManagerInterface $stores,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('agent001:sync')->setDescription('Queue full catalog synchronization.');
        $this->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view code or ID.');
        $this->addOption(
            'due',
            null,
            InputOption::VALUE_NONE,
            'Queue once per day in the 01:00–05:00 store-time window.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getOption('store');
        $storeFilter = $filter ? (int) $this->stores->getStore($filter)->getId() : null;
        $count = $this->scheduler->queue(storeFilter: $storeFilter, dueOnly: (bool) $input->getOption('due'));
        $output->writeln((string) $count . ' store views queued. Run agent001:work to process them.');
        return Command::SUCCESS;
    }
}
