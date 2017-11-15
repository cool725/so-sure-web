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
                'Pretend its this date'
            )
            ->addOption(
                'policyNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'Show scheduled payments for a policy'
            )
            ->addOption(
                'policyId',
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
                'create',
                null,
                InputOption::VALUE_NONE,
                'If policy number is present, create policy. WARNING - will skip payment'
            )
            ->addOption(
                'skip-email',
                null,
                InputOption::VALUE_NONE,
                'Do not send email report if present'
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Show all unpaid, rather than those close to expiry'
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
        $policyId = $input->getOption('policyId');
        $create = true === $input->getOption('create');
        $updatePotValue = $input->getOption('update-pot-value');
        $adjustScheduledPayments = $input->getOption('adjust-scheduled-payments');
        $skipEmail = true === $input->getOption('skip-email');
        $all = true === $input->getOption('all');
        $validateDate = null;
        if ($date) {
            $validateDate = new \DateTime($date);
        }

        $policyService = $this->getContainer()->get('app.policy');
        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $policyRepo = $dm->getRepository(Policy::class);

        if ($policyNumber || $policyId) {
            $policy = null;
            if ($policyNumber) {
                $policy = $policyRepo->findOneBy(['policyNumber' => $policyNumber]);
            } elseif ($policyId) {
                $policy = $policyRepo->find($policyId);
            }
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy for %s', $policyNumber));
            }
            if ($create) {
                $policyService->create($policy, null, true);
                $output->writeln(sprintf('Policy %s created', $policy->getPolicyNumber()));
                return;
            }

            $blank = [];
            $this->header($policy, $blank, $lines);

            if ($policy->isCancelled()) {
                $lines[] = sprintf(
                    'Policy is cancelled. Refund %f / Commission %f',
                    $policy->getRefundAmount($validateDate),
                    $policy->getRefundCommissionAmount($validateDate)
                );
            }
            $data = [
                'warnClaim' => true,
                'prefix' => $prefix,
                'validateDate' => $validateDate,
                'adjustScheduledPayments' => $adjustScheduledPayments,
                'updatePotValue' => $updatePotValue,
                'all' => true,
            ];
            $this->validatePolicy($policy, $policies, $lines, $data);
            if ($updatePotValue || $adjustScheduledPayments) {
                $dm->flush();
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
                $data = [
                    'warnClaim' => false,
                    'prefix' => $prefix,
                    'validateDate' => $validateDate,
                    'adjustScheduledPayments' => false,
                    'updatePotValue' => false,
                    'all' => $all,
                ];
                $this->validatePolicy($policy, $policies, $lines, $data);
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

            $lines[] = '';
            $lines[] = '';
            $lines[] = '';
            $lines[] = 'Wait Claims';
            $lines[] = '-------------';
            $lines[] = '';
            $repo = $dm->getRepository(Policy::class);
            $waitClaimPolicies = $repo->findBy(['status' => Policy::STATUS_EXPIRED_WAIT_CLAIM]);
            foreach ($waitClaimPolicies as $policy) {
                $lines[] = sprintf(
                    'Policy %s is expired with an active claim that needs resolving ASAP',
                    $policy->getPolicyNumber()
                );
            }
            if (count($waitClaimPolicies) == 0) {
                $lines[] = 'No wait claimable policies';
            }
            $lines[] = '';
            $lines[] = '';
        }

        if (!$skipEmail) {
            $mailer = $this->getContainer()->get('app.mailer');
            $mailer->send('Policy Validation & Pending Cancellations', 'tech@so-sure.com', implode('<br />', $lines));
        }

        $output->writeln(implode(PHP_EOL, $lines));
        $output->writeln('Finished');
    }

    private function validatePolicy(Policy $policy, &$policies, &$lines, $data)
    {
        $closeToExpiration = false;
        if ($policy->getPolicyExpirationDate()) {
            $date = $data['validateDate'] ? $data['validateDate'] : new \DateTime();
            $diff = $date->diff($policy->getPolicyExpirationDate());
            $closeToExpiration = $diff->days < 14 && $diff->invert == 0;
        }
        if ($policy->isPolicyPaidToDate($data['validateDate']) === false) {
            if ($data['all'] || $closeToExpiration) {
                $this->header($policy, $policies, $lines);
                $data['warnClaim'] = true;
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
                $lines[] = $this->failurePaymentMessage(
                    $policy,
                    $data['prefix'],
                    $data['validateDate']
                );
            }
        }
        if ($policy->isPotValueCorrect() === false) {
            $this->header($policy, $policies, $lines);
            $lines[] = $this->failurePotValueMessage($policy);
            if ($data['updatePotValue']) {
                $policy->updatePotValue();
                $lines[] = 'Updated pot value';
                $lines[] = $this->failurePotValueMessage($policy);
            }
        }
        if ($policy->hasCorrectIptRate() === false) {
            $this->header($policy, $policies, $lines);
            $lines[] = $this->failureIptRateMessage($policy);
        }
        if ($policy->hasCorrectPolicyStatus($data['validateDate']) === false) {
            $this->header($policy, $policies, $lines);
            $data['warnClaim'] = true;
            $lines[] = $this->failureStatusMessage($policy, $data['prefix'], $data['validateDate']);
        }
        if ($policy->arePolicyScheduledPaymentsCorrect() === false) {
            $this->header($policy, $policies, $lines);
            $data['warnClaim'] = true;
            if ($data['adjustScheduledPayments']) {
                if ($policyService->adjustScheduledPayments($policy)) {
                    $lines[] = sprintf(
                        'Adjusted Incorrect scheduled payments',
                        $policy->getPolicyNumber()
                    );
                } else {
                    $policy = $this->dm->merge($policy);
                    $lines[] = sprintf(
                        'WARNING!! Failed to adjusted Incorrect scheduled payments',
                        $policy->getPolicyNumber()
                    );
                }
            } else {
                if ($closeToExpiration) {
                    $lines[] = sprintf(
                        'Expected Incorrect scheduled payments - within 2 weeks of cancellation date',
                        $policy->getPolicyNumber()
                    );
                } else {
                    $lines[] = sprintf(
                        'WARNING!! Incorrect scheduled payments',
                        $policy->getPolicyNumber()
                    );
                }
                $lines[] = $this->failureScheduledPaymentsMessage($policy, $data['validateDate']);
            }
        }
        if ($policy->hasCorrectCommissionPayments($data['validateDate']) === false) {
            $this->header($policy, $policies, $lines);
            $data['warnClaim'] = true;
            $lines[] = $this->failureCommissionMessage($policy, $data['prefix'], $data['validateDate']);
        }
        if ($data['warnClaim'] && $policy->hasOpenClaim()) {
            $this->header($policy, $policies, $lines);
            $lines[] = sprintf(
                'WARNING!! - Policy %s has an open claim that should be resolved prior to cancellation',
                $policy->getPolicyNumber()
            );
        }
        if ($data['warnClaim'] && $policy->hasMonetaryClaimed()) {
            $this->header($policy, $policies, $lines);
            $lines[] = sprintf(
                'WARNING!! - Prior successful claim (extra care should be used to avoid cancellation)',
                $policy->getPolicyNumber()
            );
        }
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
            'Unexpected status %s %s',
            $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId(),
            $policy->getStatus()
        );
    }

    private function failureCommissionMessage($policy, $prefix, $date)
    {
        return sprintf(
            'Unexpected commission for policy %s',
            $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId()
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

    private function failureIptRateMessage($policy)
    {
        return sprintf(
            'Unexpected ipt rate %0.2f (Expected %0.2f)',
            $policy->getPremium()->getIptRate(),
            $policy->getCurrentIptRate($policy->getStart())
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

    private function failureScheduledPaymentsMessage($policy, $date)
    {
        $scheduledPayments = $policy->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);
        $totalScheduledPayments = ScheduledPayment::sumScheduledPaymentAmounts($scheduledPayments);

        // @codingStandardsIgnoreStart
        return sprintf(
            'Total Premium £%0.2f Payments Made £%0.2f (£%0.2f credited) Scheduled Payments £%0.2f Outstanding Premium £%0.2f',
            $policy->getYearlyPremiumPrice(),
            $policy->getTotalSuccessfulPayments($date),
            $policy->getPremiumPaid(),
            $totalScheduledPayments,
            $policy->getOutstandingPremium()
        );
        // @codingStandardsIgnoreEnd
    }
}
