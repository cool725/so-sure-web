<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\User;

class SalvaManualPolicyCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:salva:manual:policy')
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
            ->addArgument(
                'memory',
                InputArgument::REQUIRED,
                'memory'
            )
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'date to create'
            )
            ->addOption(
                'payments',
                null,
                InputOption::VALUE_REQUIRED,
                '1 for yearly, 12 monthly',
                12
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $email = $input->getArgument('email');
        $imei = $input->getArgument('imei');
        $device = $input->getArgument('device');
        $memory = $input->getArgument('memory');
        $payments = $input->getOption('payments');
        $date = $input->getOption('date');
        if ($date) {
            $date = new \DateTime($date);
        } else {
            $date = new \DateTime();
        }

        $phone = $this->getPhone($device, $memory);

        $policyService = $this->getContainer()->get('app.policy');
        $judopay = $this->getContainer()->get('app.judopay');
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');

        $user = $this->getUser($email);
        if (!$user->getBirthday()) {
            $user->setBirthday(new \DateTime('1980-01-01'));
        }
        $phone = $this->getPhone($device, $memory);

        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);
        $policy->setPhone($phone, $date);
        $policy->setImei($imei);

        $dm->persist($policy);
        $dm->flush();

        if ($payments == 12) {
            $amount = $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date);
        } elseif ($payments = 1) {
            $amount = $phone->getCurrentPhonePrice()->getYearlyPremiumPrice($date);
        } else {
            throw new \Exception('1 or 12 payments only');
        }

        $details = $judopay->testPayDetails(
            $user,
            $policy->getId(),
            $amount,
            '4976 0000 0000 3436',
            '12/20',
            '452',
            $policy->getId()
        );
        // @codingStandardsIgnoreStart
        $judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}",
            $date
        );
        // @codingStandardsIgnoreEnd

        $output->writeln(sprintf('Created Policy %s / %s', $policy->getPolicyNumber(), $policy->getId()));
    }

    private function getUser($email)
    {
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(User::class);

        return $repo->findOneBy(['emailCanonical' => strtolower($email)]);
    }

    private function getPhone($device, $memory = null)
    {
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $phoneRepo = $dm->getRepository(Phone::class);

        if ($memory) {
            return $phoneRepo->findOneBy(['devices' => $device, 'memory' => (int)$memory]);
        } else {
            return $phoneRepo->findOneBy(['devices' => $device]);
        }
    }
}
