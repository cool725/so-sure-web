<?php

namespace AppBundle\Command;

use AppBundle\Service\CheckoutService;
use AppBundle\Service\JudopayService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckoutCommand extends ContainerAwareCommand
{
    /** @var CheckoutService  */
    protected $checkoutService;

    public function __construct(CheckoutService $checkoutService)
    {
        parent::__construct();
        $this->checkoutService = $checkoutService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:checkout')
            ->setDescription('Check a charge')
            ->addOption(
                'chargeId',
                null,
                InputOption::VALUE_REQUIRED,
                'Charge to check'
            )
            ->addOption(
                'pageSize',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of records to check',
                10
            )
            ->addOption(
                'judo-token-migration-file',
                null,
                InputOption::VALUE_REQUIRED,
                'CSV File to perform token migrations from judo to checkout',
                null
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $chargeId = $input->getOption('chargeId');
        $pageSize = $input->getOption('pageSize');
        $judoTokenMigrationFile = $input->getOption('judo-token-migration-file');
        if ($chargeId) {
            $details = $this->checkoutService->getCharge($chargeId, false, false);
            $output->writeln(json_encode($details, JSON_PRETTY_PRINT));
        } elseif ($judoTokenMigrationFile) {
            $results = $this->checkoutService->tokenMigration($judoTokenMigrationFile);
            $output->writeln(sprintf('%s', json_encode($results, JSON_PRETTY_PRINT)));
        } else {
            $results = $this->checkoutService->getTransactions($pageSize);
            $output->writeln(sprintf('%d Entries %s', $pageSize, json_encode($results, JSON_PRETTY_PRINT)));
        }
    }
}
