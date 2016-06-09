<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PhonePolicy;

class ScheduledPaymentCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:scheduled:payment')
            ->setDescription('Run any payments that are scheduled to run')
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'scheduled id'
            )
            ->addOption(
                'policyNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'Show scheduled payments for a policy'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getOption('id');
        $policyNumber = $input->getOption('policyNumber');

        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $judoPay = $this->getContainer()->get('app.judopay');
        $repo = $dm->getRepository(ScheduledPayment::class);
        if ($id) {
            $scheduledPayment = $repo->find($id);
            $judoPay->scheduledPayment($scheduledPayment);
            $output->writeln(sprintf(
                'Policy %s Status %s Amount %s',
                $scheduledPayment->getPolicy()->getPolicyNumber(),
                $scheduledPayment->getPayment()->getResult(),
                $scheduledPayment->getAmount()
            ));
        } elseif ($policyNumber) {
            $policyRepo = $dm->getRepository(PhonePolicy::class);
            $policy = $policyRepo->findOneBy(['policyNumber' => $policyNumber]);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy for %s', $policyNumber));
            }
            foreach ($policy->getScheduledPayments() as $scheduledPayment) {
                $output->writeln(sprintf(
                    'Scheduled %s Status %s Amount %s',
                    $scheduledPayment->getScheduled()->format(\DateTime::ATOM),
                    $scheduledPayment->getPayment() ? $scheduledPayment->getPayment()->getResult() : 'n/a',
                    $scheduledPayment->getAmount()
                ));
            }
        } else {
            $scheduledPolicies = $repo->findScheduled();
            foreach ($scheduledPolicies as $scheduledPolicy) {
                $output->writeln($scheduledPolicy->getId());
            }
        }
    }
}
