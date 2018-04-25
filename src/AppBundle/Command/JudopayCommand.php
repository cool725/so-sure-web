<?php

namespace AppBundle\Command;

use AppBundle\Service\JudopayService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class JudopayCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:judopay')
            ->setDescription('Check a receipt')
            ->addOption(
                'receiptId',
                null,
                InputOption::VALUE_REQUIRED,
                'Receipt to check'
            )
            ->addOption(
                'pageSize',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of records to check',
                10
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $receiptId = $input->getOption('receiptId');
        $pageSize = $input->getOption('pageSize');
        /** @var JudopayService $judopay */
        $judopay = $this->getContainer()->get('app.judopay');
        if ($receiptId) {
            $details = $judopay->getReceipt($receiptId, false, false);
            $output->writeln(json_encode($details, JSON_PRETTY_PRINT));
        } else {
            $results = $judopay->getTransactions($pageSize);
            $output->writeln(sprintf('%d Entries %s', $pageSize, json_encode($results, JSON_PRETTY_PRINT)));
        }
    }
}
