<?php

namespace AppBundle\Command;

use AppBundle\Service\SalvaExportService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class SalvaExportClaimsCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:salva:export:claims')
            ->setDescription('Export all open and recently closed claims to salva')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'date'
            )
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'date',
                7
            )
            ->addOption(
                's3',
                null,
                InputOption::VALUE_NONE,
                'Upload to s3'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $s3 = true === $input->getOption('s3');
        $days = $input->getOption('days');
        $date = new \DateTime($input->getOption('date'));
        /** @var SalvaExportService $salva */
        $salva = $this->getContainer()->get('app.salva');
        $data = $salva->exportClaims($s3, $date, $days);
        $output->write(implode(PHP_EOL, $data));
        $output->writeln('');
    }
}
