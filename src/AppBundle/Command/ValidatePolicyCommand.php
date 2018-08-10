<?php

namespace AppBundle\Command;

use AppBundle\Classes\Salva;
use AppBundle\Document\BankAccount;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\File\PicSureFile;
use AppBundle\Document\ScheduledPayment;

class ValidatePolicyCommand extends BaseCommand
{
    use CurrencyTrait;

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
                'validate-premiums',
                null,
                InputOption::VALUE_NONE,
                'adjust premiums on partial and pending policies'
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
                'unpaid',
                null,
                InputOption::VALUE_REQUIRED,
                'all, expiry, none'
            )
            ->addOption(
                'skip-cancelled',
                null,
                InputOption::VALUE_NONE,
                'Do not validate cancelled policies'
            )
            ->addOption(
                'resync-picsure-s3file-metadata',
                null,
                InputOption::VALUE_NONE,
                'Set the picsure metadata from S3 metadata'
            )
            ->addOption(
                'resync-picsure-s3file-status',
                null,
                InputOption::VALUE_NONE,
                'Set the picsure status from the policy on the s3file metadata'
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
        $validatePremiums = $input->getOption('validate-premiums');
        $skipEmail = true === $input->getOption('skip-email');
        $skipCancelled = true === $input->getOption('skip-cancelled');
        $unpaid = $input->getOption('unpaid');
        if (!in_array($unpaid, [null, 'all', 'expiry', 'none'])) {
            throw new \Exception(sprintf('Unknown option for unpaid: %s', $unpaid));
        }
        $resyncPicsureMetadata = true === $input->getOption('resync-picsure-s3file-metadata');
        $resyncPicsure = true === $input->getOption('resync-picsure-s3file-status');
        $validateDate = null;
        if ($date) {
            $validateDate = new \DateTime($date);
        }

        if ($resyncPicsure) {
            $this->resyncPicsureStatus();
        } elseif ($resyncPicsureMetadata) {
            $this->resyncPicsureMetadata();
        } else {
            /** @var PolicyService $policyService */
            $policyService = $this->getContainer()->get('app.policy');
            $dm = $this->getManager();
            /** @var PolicyRepository $policyRepo */
            $policyRepo = $dm->getRepository(Policy::class);

            if ($policyNumber || $policyId) {
                /** @var Policy $policy */
                $policy = null;
                if ($policyNumber) {
                    /** @var Policy $policy */
                    $policy = $policyRepo->findOneBy(['policyNumber' => $policyNumber]);
                } elseif ($policyId) {
                    /** @var Policy $policy */
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

                $data = [
                    'warnClaim' => true,
                    'prefix' => $prefix,
                    'validateDate' => $validateDate,
                    'adjustScheduledPayments' => $adjustScheduledPayments,
                    'validate-premiums' => $validatePremiums,
                    'updatePotValue' => $updatePotValue,
                    'unpaid' => 'all',
                    'validateCancelled' => !$skipCancelled,
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
                    $data = [
                        'warnClaim' => false,
                        'prefix' => $prefix,
                        'validateDate' => $validateDate,
                        'adjustScheduledPayments' => false,
                        'validate-premiums' => $validatePremiums,
                        'updatePotValue' => false,
                        'unpaid' => $unpaid,
                        'validateCancelled' => !$skipCancelled,
                    ];
                    $this->validatePolicy($policy, $policies, $lines, $data);
                }

                $lines[] = '';
                $lines[] = '';
                $lines[] = '';
                $lines[] = 'Pending Cancellations';
                $lines[] = '-------------';
                $lines[] = '';
                /** @var PolicyService $policyService */
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
                            'WARNING!! - Policy %s has an open claim (resolved prior to cancellation)',
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
                /** @var MailerService $mailer */
                $mailer = $this->getContainer()->get('app.mailer');
                $mailer->send(
                    'Policy Validation & Pending Cancellations',
                    'tech+ops@so-sure.com',
                    implode('<br />', $lines)
                );
            }

            $output->writeln(implode(PHP_EOL, $lines));
            $output->writeln('Finished');
        }
    }

    private function validatePolicy(Policy $policy, &$policies, &$lines, $data)
    {
        if (!$data['validateCancelled'] && $policy->isCancelled()) {
            return;
        }
        try {
            $closeToExpiration = false;
            if ($policy->getPolicyExpirationDate()) {
                $date = $data['validateDate'] ? $data['validateDate'] : new \DateTime();
                $diff = $date->diff($policy->getPolicyExpirationDate());
                $closeToExpiration = $diff->days < 14 && $diff->invert == 0;
            }
            if ($policy->isPolicyPaidToDate($data['validateDate']) === false) {
                if ($data['unpaid'] == 'all' || ($closeToExpiration && $data['unpaid'] == 'expiry')) {
                    $this->header($policy, $policies, $lines);
                    $lines[] = "Not Paid To Date";
                    $lines[] = sprintf(
                        'Next attempt: %s.',
                        $policy->getNextScheduledPayment() && $policy->getNextScheduledPayment()->getScheduled() ?
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
                $lines[] = $this->failureStatusMessage($policy, $data['prefix'], $data['validateDate']);
            }
            if ($policy->arePolicyScheduledPaymentsCorrect(true, $data['validateDate']) === false) {
                $this->header($policy, $policies, $lines);
                if ($data['adjustScheduledPayments']) {
                    /** @var PolicyService $policyService */
                    $policyService = $this->getContainer()->get('app.policy');
                    if ($policyService->adjustScheduledPayments($policy)) {
                        $lines[] = sprintf(
                            'Adjusted Incorrect scheduled payments for policy %s',
                            $policy->getPolicyNumber()
                        );
                    } else {
                        $policy = $this->getManager()->merge($policy);
                        $lines[] = sprintf(
                            'WARNING!! Failed to adjusted Incorrect scheduled payments for policy %s',
                            $policy->getPolicyNumber()
                        );
                    }
                } else {
                    $lines[] = sprintf(
                        'WARNING!! Incorrect scheduled payments for policy %s',
                        $policy->getPolicyNumber()
                    );
                    $lines[] = $this->failureScheduledPaymentsMessage($policy, $data['validateDate']);
                }
            }

            // allow up to 1 month difference for non-active policies
            $allowedVariance = Salva::MONTHLY_TOTAL_COMMISSION - 0.01;
            if ((!$policy->isActive(true) &&
                $policy->hasCorrectCommissionPayments($data['validateDate'], $allowedVariance) === false) ||
                ($policy->isActive(true) && $policy->hasCorrectCommissionPayments($data['validateDate']) === false)
            ) {
                // Ignore a couple of policies that should have been cancelled unpaid, but went to expired
                if (!in_array($policy->getId(), [
                    '5960afe142bece15ca46c796',
                    '5963fe30e57c396d46347475',
                    '596765ef42bece52d026aa65',
                    '5970d065b674b62bac4be365',
                    '5973293aa603ad542d4ed949',
                ])) {
                    $this->header($policy, $policies, $lines);
                    $lines[] = $this->failureCommissionMessage($policy, $data['prefix'], $data['validateDate']);
                }
            }

            if ($data['validate-premiums'] && (!$policy->getStatus() ||
                in_array($policy->getStatus(), [Policy::STATUS_PENDING, Policy::STATUS_MULTIPAY_REJECTED]))) {
                /** @var PolicyService $policyService */
                $policyService = $this->getContainer()->get('app.policy');
                if ($policyService->validatePremium($policy)) {
                    $lines[] = sprintf(
                        'WARNING!! - Policy %s has its premium updated',
                        $policy->getPolicyNumber()
                    );
                }
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
                    'WARNING!! - Prior successful claim (care should be used to avoid cancellation) for %s',
                    $policy->getPolicyNumber()
                );
            }
            $refund = $policy->getRefundAmount();
            $refundCommission = $policy->getRefundCommissionAmount();
            if (($refund > 0 && !$this->areEqualToTwoDp(0, $refund)) ||
                ($refundCommission > 0 && !$this->areEqualToTwoDp(0, $refundCommission))) {
                $lines[] = sprintf(
                    'Warning!! Refund Due. Refund %f / Commission %f',
                    $refund,
                    $refundCommission
                );
            }
            // bacs checks are only necessary on active policies
            if ($policy->getUser()->hasBacsPaymentMethod() && $policy->isActive(true)) {
                $bacsPayments = count($policy->getPaymentsByType(BacsPayment::class));
                $bankAccount = $policy->getUser()->getBacsPaymentMethod()->getBankAccount();
                if ($bankAccount->getMandateStatus() == BankAccount::MANDATE_SUCCESS) {
                    $isFirstPayment = $bankAccount->isFirstPayment();
                    if ($bacsPayments >= 1 && $isFirstPayment) {
                        $lines[] = 'Warning!! 1 or more bacs payments, yet bank has first payment flag set';
                    } elseif ($bacsPayments == 0 && !$isFirstPayment) {
                        $lines[] = 'Warning!! No bacs payments, yet bank does not have first payment flag set';
                    }
                    if ($bacsPayments == 0 && $bankAccount->getInitialNotificationDate() > new \DateTime()) {
                        $lines[] = 'Warning!! There are no bacs payments, yet its past the initial notification date';
                    }
                }
            }
        } catch (\Exception $e) {
            // TODO: May want to swallow some exceptions here
            throw $e;
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

    private function failureCommissionMessage(Policy $policy, $prefix, $date)
    {
        return sprintf(
            'Unexpected commission for policy %s (Paid: %0.2f Expected: %0.2f)',
            $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId(),
            $policy->getTotalCommissionPaid(),
            $policy->getExpectedCommission($date)
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

    private function failureScheduledPaymentsMessage(Policy $policy, $date)
    {
        $scheduledPayments = $policy->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);
        $totalScheduledPayments = ScheduledPayment::sumScheduledPaymentAmounts($scheduledPayments);
        $billingDayIssue = $policy->arePolicyScheduledPaymentsCorrect(false, $date);

        // @codingStandardsIgnoreStart
        return sprintf(
            'Total Premium: £%0.2f; Payments Made: £%0.2f (£%0.2f credited); Scheduled Payments: £%0.2f; Outstanding Premium: £%0.2f; Billing Day Issue: %s',
            $policy->getYearlyPremiumPrice(),
            $policy->getTotalSuccessfulPayments($date),
            $policy->getPremiumPaid(),
            $totalScheduledPayments,
            $policy->getOutstandingPremium(),
            $billingDayIssue ? 'Yes' : 'No'
        );
        // @codingStandardsIgnoreEnd
    }

    private function resyncPicsureStatus()
    {
        $policyRepo = $this->getManager()->getRepository(PhonePolicy::class);

        $picsurePolicies = $policyRepo->findBy(
            ['picSureStatus' => ['$in' => [PhonePolicy::PICSURE_STATUS_APPROVED,
                                        PhonePolicy::PICSURE_STATUS_INVALID,
                                        PhonePolicy::PICSURE_STATUS_REJECTED]]]
        );

        foreach ($picsurePolicies as $policy) {
            $files = $policy->getPolicyPicSureFiles();
            if (count($files) > 0) {
                $metadata = $files[0]->getMetadata();
                if (empty($metadata['picsure-status'])) {
                    $files[0]->addMetadata('picsure-status', $policy->getPicSureStatus());
                }
            }
        }
        $this->getManager()->flush();
    }

    private function resyncPicsureMetadata()
    {
        /** @var \Aws\S3\S3Client */
        $s3 = $this->getContainer()->get('aws.s3');
        $policyRepo = $this->getManager()->getRepository(PhonePolicy::class);
        $filesRepo = $this->getManager()->getRepository(PicSureFile::class);
        $picsureFiles = $filesRepo->findAll();

        $picsurePolicies = $policyRepo->findBy(
            ['picSureStatus' => ['$in' => [PhonePolicy::PICSURE_STATUS_MANUAL,
                                        PhonePolicy::PICSURE_STATUS_APPROVED,
                                        PhonePolicy::PICSURE_STATUS_INVALID,
                                        PhonePolicy::PICSURE_STATUS_REJECTED]]]
        );

        foreach ($picsurePolicies as $policy) {
            $files = $policy->getPolicyPicSureFiles();
            if (count($files) > 0) {
                foreach ($files as $file) {
                    $result = $s3->getObject(array(
                        'Bucket' => $file->getBucket(),
                        'Key'    => $file->getKey(),
                    ));
                    if (!empty($result['Metadata'])) {
                        $metadata = $file->getMetadata();
                        // for typo in the app: to be removed eventually
                        if (isset($result['Metadata']['attemps']) && !isset($metadata['picsure-attempts'])) {
                            $file->addMetadata('picsure-attempts', $result['Metadata']['attemps']);
                        }
                        if (isset($result['Metadata']['attempts']) && !isset($metadata['picsure-attempts'])) {
                            $file->addMetadata('picsure-attempts', $result['Metadata']['attempts']);
                        }
                        if (isset($result['Metadata']['suspected-fraud']) &&
                            !isset($metadata['picsure-suspected-fraud'])
                        ) {
                            $file->addMetadata('picsure-suspected-fraud', $result['Metadata']['suspected-fraud']);
                            if ($result['Metadata']['suspected-fraud'] === "1") {
                                $policy->setPicSureCircumvention(true);
                                /** @var LoggerInterface $logger */
                                $logger = $this->getContainer()->get('logger');
                                $logger->error(sprintf(
                                    'Detected pic-sure circumvention attempt for policy %s',
                                    $policy->getId()
                                ));
                            } else {
                                $policy->setPicSureCircumvention(false);
                            }
                        }
                    }
                }
            }
        }
        $this->getManager()->flush();
    }
}
