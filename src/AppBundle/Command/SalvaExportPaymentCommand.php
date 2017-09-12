<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;
use AppBundle\Document\DateTrait;

class SalvaExportPaymentCommand extends ContainerAwareCommand
{
    use DateTrait;

    protected function configure()
    {
        $this
            ->setName('sosure:salva:export:payment')
            ->setDescription('Export all payments to salva')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'date'
            )
            ->addOption(
                's3',
                null,
                InputOption::VALUE_NONE,
                'Upload to s3'
            )
            ->addOption(
                'broker-fee',
                null,
                InputOption::VALUE_NONE,
                'Include broker fee'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $s3 = true === $input->getOption('s3');
        $includeBrokerFee = true === $input->getOption('broker-fee');
        $date = $input->getOption('date');
        if ($date) {
            $date = new \DateTime($input->getOption('date'));
        } else {
            $date = $this->startOfPreviousMonth();
            $output->writeln(sprintf('Using last month %s', $date->format('Y-m')));
        }
        $salva = $this->getContainer()->get('app.salva');
        $data = $salva->exportPayments($s3, $includeBrokerFee, $date);
        $output->write(implode(PHP_EOL, $data));
        $output->writeln('');
    }
}
