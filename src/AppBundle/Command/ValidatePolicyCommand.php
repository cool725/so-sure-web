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

class ValidatePolicyCommand extends ContainerAwareCommand
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
            ->addOption(
                'update-pot-value',
                null,
                InputOption::VALUE_NONE,
                'If policy number is present, update pot value if required'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $date = $input->getOption('date');
        $prefix = $input->getOption('prefix');
        $policyNumber = $input->getOption('policyNumber');
        $updatePotValue = $input->getOption('update-pot-value');
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
            $output->writeln(sprintf('Policy %s %s paid to date', $policyNumber, $valid ? 'is' : 'is NOT'));
            if (!$valid) {
                $output->writeln($this->failureMessage($policy, $prefix, $validateDate));
            }
            $valid = $policy->isPotValueCorrect();
            $output->writeln(sprintf(
                'Policy %s %s the correct pot value',
                $policyNumber,
                $valid ? 'has' : 'does NOT have'
            ));
            if (!$valid) {
                $output->writeln($this->failurePotValueMessage($policy));
                if ($updatePotValue) {
                    $policy->updatePotValue();
                    $dm->flush();
                    $output->writeln('Updated pot value');
                    $output->writeln($this->failurePotValueMessage($policy));
                }
            }
        } else {
            $policies = $policyRepo->findAll();
            foreach ($policies as $policy) {
                if ($policy->isPolicyPaidToDate($prefix, $validateDate) === false) {
                    $output->writeln(sprintf(
                        'Policy %s is not paid to date',
                        $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId()
                    ));
                    $output->writeln($this->failurePaymentMessage($policy, $prefix, $validateDate));
                }
                if ($policy->isPotValueCorrect() === false) {
                    $output->writeln(sprintf('Policy %s has incorrect pot value', $policy->getPolicyNumber()));
                    $output->writeln($this->failurePotValueMessage($policy));
                }
            }
        }
        $output->writeln('Finished');
    }

    private function failurePaymentMessage($policy, $prefix, $date)
    {
        $totalPaid = $policy->getTotalSuccessfulPayments($prefix, $date);
        $expectedPaid = $policy->getTotalExpectedPaidToDate($prefix, $date);
        return sprintf(
            'Policy %s Paid £%0.2f Expected £%0.2f',
            $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId(),
            $totalPaid,
            $expectedPaid
        );
    }

    private function failurePotValueMessage($policy)
    {
        return sprintf(
            'Policy %s Pot Value £%0.2f Expected £%0.2f Promo Pot Value £%0.2f Expected £%0.2f',
            $policy->getPolicyNumber(),
            $policy->getPotValue(),
            $policy->calculatePotValue(),
            $policy->getPromoPotValue(),
            $policy->calculatePotValue(true)
        );
    }
}
