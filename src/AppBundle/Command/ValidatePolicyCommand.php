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
use AppBundle\Document\ScheduledPayment;

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
            ->addOption(
                'adjust-scheduled-payments',
                null,
                InputOption::VALUE_NONE,
                'If policy number is present, adjust scheduled payments'
            )
            ->addOption(
                'skip-email',
                null,
                InputOption::VALUE_NONE,
                'Do not send email report if present'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $lines = [];
        $policies = [];
        $date = $input->getOption('date');
        $prefix = $input->getOption('prefix');
        $policyNumber = $input->getOption('policyNumber');
        $updatePotValue = $input->getOption('update-pot-value');
        $adjustScheduledPayments = $input->getOption('adjust-scheduled-payments');
        $skipEmail = true === $input->getOption('skip-email');
        $validateDate = null;
        if ($date) {
            $validateDate = new \DateTime($date);
        }

        $policyService = $this->getContainer()->get('app.policy');
        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $policyRepo = $dm->getRepository(Policy::class);

        if ($policyNumber) {
            $policy = $policyRepo->findOneBy(['policyNumber' => $policyNumber]);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy for %s', $policyNumber));
            }

            $this->header($policy, [], $lines);
            if ($adjustScheduledPayments) {
                if (!$policy->arePolicyScheduledPaymentsCorrect($prefix, $validateDate)) {
                    if ($policyService->adjustScheduledPayments($policy)) {
                        $lines[] = "Successfully adjusted the scheduled payments";
                    } else {
                        $policy = $this->dm->merge($policy);
                        $lines[] = "Failed to adjust the scheduled payments";
                    }
                } else {
                    $lines[] = "Skipped adjusting scheduled payments as not required";
                }
            }

            $valid = $policy->isPolicyPaidToDate($validateDate);
            $lines[] = sprintf('%spaid to date', $valid ? '' : 'NOT ');
            if (!$valid) {
                $lines[] = $this->failurePaymentMessage($policy, $prefix, $validateDate);
            }

            $valid = $policy->hasCorrectPolicyStatus($validateDate);
            if ($valid === false) {
                $lines[] = $this->failureStatusMessage($policy, $prefix, $validateDate);
            }

            $valid = $policy->isPotValueCorrect();
            $lines[] = sprintf(
                '%s pot value',
                $valid ? 'correct' : 'INCORRECT'
            );
            if (!$valid) {
                $lines[] = $this->failurePotValueMessage($policy);
                if ($updatePotValue) {
                    $policy->updatePotValue();
                    $dm->flush();
                    $lines[] = 'Updated pot value';
                    $lines[] = $this->failurePotValueMessage($policy);
                }
            }

            $valid = $policy->arePolicyScheduledPaymentsCorrect($prefix, $validateDate);
            $lines[] = sprintf(
                '%s scheduled payments (likely incorrect if within 2 weeks of cancellation date)',
                $valid ? 'correct ' : 'INCORRECT'
            );
            if (!$valid) {
                $lines[] = $this->failureScheduledPaymentsMessage($policy, $prefix, $validateDate);
            }
            if ($policy->hasMonetaryClaimed()) {
                // @codingStandardsIgnoreStart
                $lines[] = sprintf(
                    'WARNING!! - Prior successful claim (extra care should be used to avoid cancellation)'
                );
                // @codingStandardsIgnoreEnd
            }
        } else {
            $policies = $policyRepo->findAll();
            $lines[] = 'Policy Validation';
            $lines[] = '-------------';
            $lines[] = '';
            foreach ($policies as $policy) {
                if ($prefix && !$policy->hasPolicyPrefix($prefix)) {
                    continue;
                }
                // TODO: Run financials for cancelled policies as well
                if ($policy->isCancelled()) {
                    continue;
                }
                $warnClaim = false;
                if ($policy->isPolicyPaidToDate($validateDate) === false) {
                    $this->header($policy, $policies, $lines);
                    $warnClaim = true;
                    $lines[] = "Not Paid To Date";
                    $lines[] = sprintf(
                        'Next attempt: %s.',
                        $policy->getNextScheduledPayment() ?
                            $policy->getNextScheduledPayment()->getScheduled()->format(\DateTime::ATOM) :
                            'unknown'
                    );
                    $lines[] = sprintf(
                        'Cancellation date: %s',
                        $policy->getPolicyExpirationDate() ?
                            $policy->getPolicyExpirationDate()->format(\DateTime::ATOM) :
                            'unknown'
                    );
                    $lines[] = $this->failurePaymentMessage($policy, $prefix, $validateDate);
                }
                if ($policy->isPotValueCorrect() === false) {
                    $this->header($policy, $policies, $lines);
                    $lines[] = $this->failurePotValueMessage($policy);
                }
                if ($policy->arePolicyScheduledPaymentsCorrect($prefix, $validateDate) === false) {
                    $this->header($policy, $policies, $lines);
                    $warnClaim = true;
                    $lines[] = sprintf(
                        'Incorrect scheduled payments (likely if within 2 weeks of cancellation date)',
                        $policy->getPolicyNumber()
                    );
                    $lines[] = $this->failureScheduledPaymentsMessage($policy, $prefix, $validateDate);
                }
                if ($policy->hasCorrectPolicyStatus($validateDate) === false) {
                    $this->header($policy, $policies, $lines);
                    $warnClaim = true;
                    $lines[] = $this->failureStatusMessage($policy, $prefix, $validateDate);
                }
                if ($warnClaim && $policy->hasOpenClaim()) {
                    $this->header($policy, $policies, $lines);
                    $lines[] = sprintf(
                        'WARNING!! - Open claim (resolved prior to cancellation)',
                        $policy->getPolicyNumber()
                    );
                }
                if ($warnClaim && $policy->hasMonetaryClaimed()) {
                    $this->header($policy, $policies, $lines);
                    // @codingStandardsIgnoreStart
                    $lines[] = sprintf(
                        'WARNING!! - Prior successful claim (extra care should be used to avoid cancellation)',
                        $policy->getPolicyNumber()
                    );
                    // @codingStandardsIgnoreEnd
                }
            }

            $lines[] = '';
            $lines[] = '';
            $lines[] = '';
            $lines[] = 'Pending Cancellations';
            $lines[] = '-------------';
            $lines[] = '';
            $policyService = $this->getContainer()->get('app.policy');
            $pending = $policyService->getPoliciesPendingCancellation(true, $prefix);
            foreach ($pending as $policy) {
                $lines[] = sprintf(
                    'Policy %s is pending cancellation on %s',
                    $policy->getPolicyNumber(),
                    $policy->getPendingCancellation()->format(\DateTime::ATOM)
                );
                if ($policy->hasOpenClaim()) {
                    $lines[] = sprintf(
                        'WARNING!! - Policy %s has an open claim that should be resolved prior to cancellation',
                        $policy->getPolicyNumber()
                    );
                }
            }
            if (count($pending) == 0) {
                $lines[] = 'No pending cancellations';
            }
        }

        if (!$skipEmail) {
            $mailer = $this->getContainer()->get('app.mailer');
            $mailer->send('Policy Validation & Pending Cancellations', 'tech@so-sure.com', implode('<br />', $lines));
        }

        $output->writeln(implode(PHP_EOL, $lines));
        $output->writeln('Finished');
    }

    private function header($policy, &$policies, &$lines)
    {
        if (!isset($policies[$policy->getId()])) {
            $lines[] = '';
            $lines[] = $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId();
            $lines[] = '---';
            $policies[$policy->getId()] = true;
        }
    }

    private function failureStatusMessage($policy, $prefix, $date)
    {
        return sprintf(
            'Unexpected status %s',
            $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId(),
            $policy->getStatus()
        );
    }

    private function failurePaymentMessage($policy, $prefix, $date)
    {
        $totalPaid = $policy->getTotalSuccessfulPayments($date);
        $expectedPaid = $policy->getTotalExpectedPaidToDate($date);
        return sprintf(
            'Paid £%0.2f Expected £%0.2f',
            $totalPaid,
            $expectedPaid
        );
    }

    private function failurePotValueMessage($policy)
    {
        return sprintf(
            'Pot Value £%0.2f Expected £%0.2f Promo Pot Value £%0.2f Expected £%0.2f',
            $policy->getPotValue(),
            $policy->calculatePotValue(),
            $policy->getPromoPotValue(),
            $policy->calculatePotValue(true)
        );
    }

    private function failureScheduledPaymentsMessage($policy, $prefix, $date)
    {
        $scheduledPayments = $policy->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);
        $totalScheduledPayments = ScheduledPayment::sumScheduledPaymentAmounts($scheduledPayments, $prefix);

        return sprintf(
            'Total Premium £%0.2f Payments Made £%0.2f Scheduled Payments £%0.2f',
            $policy->getYearlyPremiumPrice(),
            $policy->getTotalSuccessfulPayments($date),
            $totalScheduledPayments
        );
    }
}
