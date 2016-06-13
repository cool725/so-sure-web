<?php

namespace AppBundle\Command;

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
            ->addArgument(
                'receiptId',
                InputArgument::REQUIRED,
                'Receipt to check'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $receiptId = $input->getArgument('receiptId');
        $judopay = $this->getContainer()->get('app.judopay');
        $details = $judopay->getReceipt($receiptId);
        $output->writeln(json_encode($details, JSON_PRETTY_PRINT));
    }
}
