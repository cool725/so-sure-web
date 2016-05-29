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
            ->addOption(
                'serial',
                null,
                InputOption::VALUE_REQUIRED,
                'serial'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $imei = $input->getArgument('imei');
        $serial = $input->getOption('serial');
        $imeiService = $this->getContainer()->get('app.imei');
        if ($imeiService->checkImei(new Phone(), $imei)) {
            print sprintf("Imei %s is good\n", $imei);
        } else {
            print sprintf("Imei %s failed validation\n", $imei);
        }

        if ($serial) {
            if ($imeiService->checkSerial(new Phone(), $serial)) {
                print sprintf("Serial %s is good\n", $serial);
            } else {
                print sprintf("Serial %s failed validation\n", $serial);
            }
        }
    }
}
