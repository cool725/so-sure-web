<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class SalvaPaymentCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:salva:payment')
            ->setDescription('Export all payments to salva')
            ->addArgument(
                'year',
                InputArgument::REQUIRED,
                'Year'
            )
            ->addArgument(
                'month',
                InputArgument::REQUIRED,
                'Month'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $year = $input->getArgument('year');
        $month = $input->getArgument('month');
        $salva = $this->getContainer()->get('app.salva');
        $salva->exportPayments($year, $month);
    }
}
