<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class SalvaPolicyCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:salva:policy')
            ->setDescription('Export all policies to salva')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $salva = $this->getContainer()->get('app.salva');
        $output->write($salva->exportPolicies());
    }
}
