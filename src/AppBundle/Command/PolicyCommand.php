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
use AppBundle\Document\JudoPayment;
use AppBundle\Document\User;

class PolicyCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:salva-phone-policy')
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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $email = $input->getArgument('email');
        $imei = $input->getArgument('imei');
        $device = $input->getArgument('device');
        $memory = $input->getArgument('memory');
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
        $policy->setPhone($phone);
        $policy->setImei($imei);

        $dm->persist($policy);
        $dm->flush();

        $details = $judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
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
            "{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}"
        );
        // @codingStandardsIgnoreEnd

        /*
        $payment = new JudoPayment();
        $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt($receiptId);
        $policy->addPayment($payment);
        */
        $policyService->create($policy);
        $output->writeln(sprintf('Created Policy %s / %s', $policy->getPolicyNumber(), $policy->getId()));
    }

    private function getUser($email)
    {
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(User::class);

        return $repo->findOneBy(['emailCanonical' => $email]);
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
