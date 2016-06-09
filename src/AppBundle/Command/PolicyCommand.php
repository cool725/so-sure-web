<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\User;

class PolicyCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:policy')
            ->setDescription('Manually create a policy')
            ->addArgument(
                'email',
                InputArgument::REQUIRED,
                'email of user'
            )
            ->addArgument(
                'imei',
                InputArgument::REQUIRED,
                'Imei'
            )
            ->addArgument(
                'device',
                InputArgument::REQUIRED,
                'device'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $email = $input->getArgument('email');
        $imei = $input->getArgument('imei');
        $device = $input->getArgument('device');
        $phone = $this->getPhone($device);
        $policyService = $this->getContainer()->get('app.policy');
        $policy = new PhonePolicy();
        $policy->setUser($this->getUser($email));
        $policy->setPhone($this->getPhone($device));
        $policy->setImei($imei);
        
        $payment = new JudoPayment();
        $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $dm->persist($policy);

        $policyService->create($policy);
        $output->writeln(sprintf('Created Policy %s / %s', $policy->getPolicyNumber(), $policy->getId()));
    }

    private function getUser($email)
    {
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(User::class);

        return $repo->findOneBy(['emailCanonical' => $email]);
    }

    private function getPhone($device)
    {
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $phoneRepo = $dm->getRepository(Phone::class);

        return $phoneRepo->findOneBy(['devices' => $device]);
    }
}
