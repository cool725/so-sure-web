<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;

class PolicyPaidCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:policy:validate')
            ->setDescription('Validate policy payments')
            ->addOption(
                'prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy prefix'
            )
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'Pretent its this date'
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
        $date = $input->getOption('date');
        $prefix = $input->getOption('prefix');
        $policyNumber = $input->getOption('policyNumber');
        $validateDate = null;
        if ($date) {
            $validateDate = new \DateTime($date);
        }

        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $policyRepo = $dm->getRepository(Policy::class);

        if ($policyNumber) {
            $policy = $policyRepo->findOneBy(['policyNumber' => $policyNumber]);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy for %s', $policyNumber));
            }
            $valid = $policy->isPolicyPaidToDate($prefix, $validateDate);
            $output->writeln(sprintf('Policy %s %s paid to date', $policyNumber, $valid ? 'is' : 'is not'));
            if (!$valid) {
                $output->writeln($this->failureMessage($policy, $prefix, $validateDate));
            }
        } else {
            $policies = $policyRepo->findAll();
            foreach ($policies as $policy) {
               if ($policy->isPolicyPaidToDate($prefix, $validateDate) === false) {
                    $output->writeln(sprintf('Policy %s is not paid to date', $policy->getPolicyNumber()));
                    $output->writeln($this->failureMessage($policy, $prefix, $validateDate));
               }
            }
        }
        $output->writeln('Finished');
    }

    private function failureMessage($policy, $prefix, $date)
    {
        $totalPaid = $policy->getTotalSuccessfulPayments($prefix, $date);
        $expectedPaid = $policy->getTotalExpectedPaidToDate($prefix, $date);
        return sprintf('Policy %s Paid £%0.2f Expected £%0.2f', $policy->getPolicyNumber(), $totalPaid, $expectedPaid);
    }
}
