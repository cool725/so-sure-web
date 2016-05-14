<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;

class ImeiCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:imei')
            ->setDescription('Run manual check on imei')
            ->addArgument(
                'imei',
                InputArgument::REQUIRED,
                'Imei'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $imei = $input->getArgument('imei');
        $imeiService = $this->getContainer()->get('app.imei');
        $imeiService->checkImei(new Phone(), $imei);
    }
}
