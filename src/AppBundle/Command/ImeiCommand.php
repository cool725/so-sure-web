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
                'Imei - this is the cheap gsma check of £0.02'
            )
            ->addOption(
                'serial',
                null,
                InputOption::VALUE_REQUIRED,
                'serial - will run make/model check of £0.05'
            )
            ->addOption(
                'claimscheck',
                null,
                InputOption::VALUE_NONE,
                'expensive £0.90 check'
            )
            ->addOption(
                'device',
                null,
                InputOption::VALUE_REQUIRED,
                'device'
            )
            ->addOption(
                'memory',
                null,
                InputOption::VALUE_REQUIRED,
                'memory'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $imei = $input->getArgument('imei');
        $serial = $input->getOption('serial');
        $device = $input->getOption('device');
        $memory = $input->getOption('memory');
        $claimscheck = $input->getOption('claimscheck');
        $phone = $this->getPhone($device, $memory);
        $imeiService = $this->getContainer()->get('app.imei');

        if ($claimscheck) {
            if ($imeiService->checkClaims($phone, $imei)) {
                print sprintf("Claimscheck %s is good\n", $imei);
            } else {
                print sprintf("Claimscheck %s failed validation\n", $imei);
            }
        } else {
            if ($imeiService->checkImei($phone, $imei)) {
                print sprintf("Imei %s is good\n", $imei);
            } else {
                print sprintf("Imei %s failed validation\n", $imei);
            }
        }

        if ($serial) {
            if ($imeiService->checkSerial($phone, $serial)) {
                print sprintf("Serial %s is good\n", $serial);
            } else {
                print sprintf("Serial %s failed validation\n", $serial);
            }
        }
    }

    private function getPhone($device, $memory)
    {
        $phone = null;
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $phoneRepo = $dm->getRepository(Phone::class);
        if ($device && $memory) {
            $phone = $phoneRepo->findOneBy(['devices' => $device, 'memory' => (int)$memory]);
        } elseif ($device) {
            $phone = $phoneRepo->findOneBy(['devices' => $device]);
        } else {
            $phones = $phoneRepo->findAll();
            while ($phone == null) {
                $phone = $phones[rand(0, count($phones) - 1)];
                if (!$phone->getCurrentPhonePrice() || $phone->getMake() == "ALL") {
                    $phone = null;
                }
            }
        }

        return $phone;
    }
}
