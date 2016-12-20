<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Policy;

class SmsCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:sms')
            ->setDescription('Send sms to user')
            ->addOption(
                'policyNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'policyNumber'
            )
            ->addOption(
                'final',
                null,
                InputOption::VALUE_NONE,
                'is final attempt'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $policyNumber = $input->getOption('policyNumber');
        $finalAttempt = true === $input->getOption('final');

        $sms = $this->getContainer()->get('app.sms');

        if ($policyNumber) {
            $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->findOneBy(['policyNumber' => $policyNumber]);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy %s', $policyNumber));
            }

            $smsTemplate = 'AppBundle:Sms:failedPayment.txt.twig';
            if ($finalAttempt) {
                $smsTemplate = 'AppBundle:Sms:failedPaymentFinal.txt.twig';
            }

            $sms->sendUser($policy, $smsTemplate, ['policy' => $policy]);
        } else {
            $output->writeln('Nothing to do');
        }
    }
}
