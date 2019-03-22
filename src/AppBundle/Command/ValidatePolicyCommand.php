<?php

namespace AppBundle\Command;

use AppBundle\Classes\Salva;
use AppBundle\Document\BankAccount;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use AppBundle\Service\RouterService;
use Aws\S3\S3Client;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Predis\Collection\Iterator\SortedSetKey;
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

class ValidatePolicyCommand extends ContainerAwareCommand
{
    use CurrencyTrait;
    use DateTrait;

    /** @var PolicyService */
    protected $policyService;

    /** @var DocumentManager */
    protected $dm;

    /** @var S3Client */
    protected $s3;

    /** @var MailerService  */
    protected $mailerService;

    /** @var RouterService */
    protected $routerService;

    /** @var LoggerInterface */
    protected $logger;

    /** @var Client */
    private $redis;

    public function __construct(
        PolicyService $policyService,
        DocumentManager $dm,
        S3Client $s3,
        MailerService $mailerService,
        RouterService $routerService,
        LoggerInterface $logger,
        Client $redis
    ) {
        parent::__construct();
        $this->policyService = $policyService;
        $this->dm = $dm;
        $this->s3 = $s3;
        $this->mailerService = $mailerService;
        $this->routerService = $routerService;
        $this->logger = $logger;
        $this->redis = $redis;
    }

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
                'flush-policy-redis',
                null,
                InputOption::VALUE_NONE,
                'Flush policies from redis DB'
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
        $csvData = [];
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
        $flushPolicyRedis = true === $input->getOption('flush-policy-redis');
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
        } elseif ($flushPolicyRedis) {
            $this->redis->del(['policy:validation']);
            $this->redis->del(['policy:validation:flags']);
        } else {
            $this->redis->del(['policy:validation']);

            /** @var PolicyRepository $policyRepo */
            $policyRepo = $this->dm->getRepository(Policy::class);

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
                    $this->policyService->create($policy, null, true);
                    $output->writeln(sprintf('Policy %s created', $policy->getPolicyNumber()));
                    return;
                }

                $lines = $this->header($policy);

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
                $lines = array_merge($lines, $this->validatePolicy($policy, $policies, $data));
                if ($updatePotValue || $adjustScheduledPayments) {
                    $this->dm->flush();
                }
            } else {
                $validations = [];
                $policies = $policyRepo->findAll();
                $lines[] = sprintf(
                    'Policy Validation (<a href="%s">Admin</a>)',
                    $this->routerService->generateUrl('admin_policy_validation', [])
                );
                $lines[] = '';
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

                    $validation = $this->validatePolicy($policy, $policies, $data);
                    if (count($validation) > 0) {
                        $lines = array_merge($lines, $this->header($policy));
                        $lines = array_merge($lines, $validation);
                        $csvData[$policy->getPolicyNumber()] = $validation;
                        $this->redis->zadd('policy:validation', [serialize([
                            'id' => $policy->getId(),
                            'policyNumber' => $policy->getPolicyNumber(),
                            'issues' => $validation,
                        ]) => 0]);
                    }
                }

                $lines[] = '';
                $lines[] = '';
                $lines[] = '';
                $lines[] = 'Pending Cancellations';
                $lines[] = '-------------';
                $lines[] = '';
                $pending = $this->policyService->getPoliciesPendingCancellation(true, $prefix);
                foreach ($pending as $policy) {
                    /** @var Policy $policy */
                    $lines[] = sprintf(
                        'Policy %s is pending cancellation on %s',
                        $policy->getPolicyNumber(),
                        $policy->getPendingCancellation() ?
                            $policy->getPendingCancellation()->format(\DateTime::ATOM) :
                            'unknown'
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
                $repo = $this->dm->getRepository(Policy::class);
                $waitClaimPolicies = $repo->findBy(['status' => Policy::STATUS_EXPIRED_WAIT_CLAIM]);
                foreach ($waitClaimPolicies as $policy) {
                    /** @var Policy $policy */
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
                $this->sendEmail($lines, $csvData);
            }

            $output->writeln(implode(PHP_EOL, $lines));
            $output->writeln('Finished');
        }
    }

    private function sendEmail($lines, $data)
    {
        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), 'sosure-policy-validation.csv');
        $csvLine = [];
        foreach ($data as $policyNumber => $errors) {
            foreach ($errors as $error) {
                $csvLine[] = sprintf('"%s", "%s"', $policyNumber, str_replace('"', "''", $error));
            }
        }
        $csvData = implode(PHP_EOL, $csvLine);
        file_put_contents($tmpFile, $csvData);

        $this->mailerService->send(
            'Policy Validation & Pending Cancellations',
            'tech+ops@so-sure.com',
            implode('<br />', $lines),
            null,
            [$tmpFile]
        );
    }

    private function validatePolicy(Policy $policy, &$policies, $data)
    {
        $lines = [];
        if (!$data['validateCancelled'] && $policy->isCancelled()) {
            return $lines;
        }
        try {
            $closeToExpiration = false;
            if ($policy->getPolicyExpirationDate()) {
                $date = $data['validateDate'] ? $data['validateDate'] : \DateTime::createFromFormat('U', time());
                $diff = $date->diff($policy->getPolicyExpirationDate());
                $closeToExpiration = $diff->days < 14 && $diff->invert == 0;
            }
            if ($policy->isPolicyPaidToDate($data['validateDate']) === false) {
                if ($data['unpaid'] == 'all' || ($closeToExpiration && $data['unpaid'] == 'expiry')) {
                    //$this->header($policy, $policies, $lines);
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
                $lines[] = $this->failurePotValueMessage($policy);
                if ($data['updatePotValue']) {
                    $policy->updatePotValue();
                    $lines[] = 'Updated pot value';
                    $lines[] = $this->failurePotValueMessage($policy);
                }
            }
            if ($policy->hasCorrectIptRate() === false) {
                $lines[] = $this->failureIptRateMessage($policy);
            }
            if ($policy->hasCorrectPolicyStatus($data['validateDate']) === false) {
                $lines[] = $this->failureStatusMessage($policy, $data['prefix'], $data['validateDate']);
            }
            if ($policy->arePolicyScheduledPaymentsCorrect(
                true,
                $data['validateDate'],
                true
            ) === false) {
                if ($data['adjustScheduledPayments']) {
                    if ($this->policyService->adjustScheduledPayments($policy)) {
                        $lines[] = sprintf(
                            'Adjusted Incorrect scheduled payments for policy %s',
                            $policy->getPolicyNumber()
                        );
                    } else {
                        /** @var Policy $policy */
                        $policy = $this->dm->merge($policy);
                        $lines[] = sprintf(
                            'WARNING!! Failed to adjusted Incorrect scheduled payments for policy %s',
                            $policy->getPolicyNumber()
                        );
                    }
                } elseif ($policy->hasPolicyOrUserBacsPaymentMethod() &&
                    $policy->getPolicyOrUserBacsBankAccount() &&
                    $policy->getPolicyOrUserBacsBankAccount()->isMandateInvalid()) {
                    // If the mandate is invalid, we can just ignore - user will be prompted to resolve the issue
                    /*
                    $lines[] = sprintf(
                        'Invalid BACS Mandate. Can ignore incorrect scheduled payments for policy %s',
                        $policy->getPolicyNumber()
                    );
                    $lines[] = $this->failureScheduledPaymentsMessage($policy, $data['validateDate']);
                    */
                } else {
                    $lines[] = sprintf(
                        'WARNING!! Incorrect scheduled payments for policy %s',
                        $policy->getPolicyNumber()
                    );
                    $lines[] = $this->failureScheduledPaymentsMessage($policy, $data['validateDate']);
                }
            }

            $allowedVariance = 0;
            // allow up to 1 month difference for non-active policies
            if (!$policy->isActive(true)) {
                $allowedVariance = Salva::MONTHLY_TOTAL_COMMISSION - 0.01;
            }
            // any pending payments should be excluded from calcs
            $pendingBacsTotalCommission = $policy->getPendingBacsPaymentsTotalCommission(true);
            $allowedVariance += abs($pendingBacsTotalCommission);

            // There are often bacs refunds on the following date; make sure to include the following day for payments
            // to pick up the refund
            $commissionDate = $data['validateDate'] ?: \DateTime::createFromFormat('U', time());
            $commissionDate = $this->getNextBusinessDay($commissionDate);

            // depending on when the chargeback occurs, we may or may not want to exclude that amount
            // but if they both don't match, then its likely to be a problem
            if ($policy->hasCorrectCommissionPayments($commissionDate, $allowedVariance) === false &&
                $policy->hasCorrectCommissionPayments($commissionDate, $allowedVariance, true) === false) {
                // Ignore a couple of policies that should have been cancelled unpaid, but went to expired
                if (!in_array($policy->getId(), Salva::$commissionValidationExclusions)) {
                    $lines[] = $this->failureCommissionMessage($policy, $data['prefix'], $commissionDate);
                }
            }

            if ($data['validate-premiums'] && (!$policy->getStatus() ||
                in_array($policy->getStatus(), [Policy::STATUS_PENDING, Policy::STATUS_MULTIPAY_REJECTED]))) {
                if ($this->policyService->validatePremium($policy)) {
                    $lines[] = sprintf(
                        'WARNING!! - Policy %s has its premium updated',
                        $policy->getPolicyNumber()
                    );
                }
            }
            if ($data['warnClaim'] && $policy->hasOpenClaim()) {
                $lines[] = sprintf(
                    'WARNING!! - Policy %s has an open claim that should be resolved prior to cancellation',
                    $policy->getPolicyNumber()
                );
            }
            if ($data['warnClaim'] && $policy->hasMonetaryClaimed()) {
                $lines[] = sprintf(
                    'WARNING!! - Prior successful claim (care should be used to avoid cancellation) for %s',
                    $policy->getPolicyNumber()
                );
            }
            $refund = $policy->getRefundAmount(false, true);
            $refundCommission = $policy->getRefundCommissionAmount(false, true);
            $pendingBacsTotal = abs($policy->getPendingBacsPaymentsTotal(true));
            $pendingBacsTotalCommission = abs($policy->getPendingBacsPaymentsTotalCommission(true));
            $refundMismatch =  $this->greaterThanZero($refund) && $refund > $pendingBacsTotal &&
                !$this->areEqualToTwoDp($refund, $pendingBacsTotal);
            $refundCommissionMismatch =  $this->greaterThanZero($refundCommission) &&
                $refundCommission > $pendingBacsTotalCommission &&
                !$this->areEqualToTwoDp($refundCommission, $pendingBacsTotalCommission);

            if (!in_array($policy->getId(), Salva::$refundValidationExclusions) &&
                ($refundMismatch ||$refundCommissionMismatch )) {
                $lines[] = sprintf(
                    'Warning!! Refund Due. Refund %0.2f [Pending %0.2f] / Commission %0.2f [Pending %0.2f]',
                    $refund,
                    $pendingBacsTotal,
                    $refundCommission,
                    $pendingBacsTotalCommission
                );
            }

            // bacs checks are only necessary on active policies
            if ($policy->hasPolicyOrUserBacsPaymentMethod() && $policy->isActive(true)) {
                $bankAccount = $policy->getPolicyOrUserBacsBankAccount();
                if ($bankAccount && $bankAccount->getMandateStatus() == BankAccount::MANDATE_SUCCESS) {
                    $bacsPayments = $policy->getPaymentsByType(BacsPayment::class);
                    $bacsPaymentCount = 0;
                    $initialDay = $this->startOfDay($bankAccount->getInitialNotificationDate());
                    foreach ($bacsPayments as $bacsPayment) {
                        /** @var BacsPayment $bacsPayment */
                        if ($bacsPayment->getDate() >= $initialDay) {
                            $bacsPaymentCount++;
                        }
                    }

                    $isFirstPayment = $bankAccount->isFirstPayment();
                    if ($bacsPaymentCount >= 1 && $isFirstPayment) {
                        $lines[] = 'Warning!! 1 or more bacs payments, yet bank has first payment flag set';
                    } elseif ($bacsPaymentCount == 0 && !$isFirstPayment) {
                        $lines[] = 'Warning!! No bacs payments, yet bank does not have first payment flag set';
                    }

                    if ($bankAccount->isAfterInitialNotificationDate()) {
                        if ($bacsPaymentCount == 0 && count($policy->getScheduledPayments()) == 0) {
                            $lines[] = 'Warning!! There are no bacs payments, yet past the initial notification date';
                        }
                    } elseif ($bankAccount->isAfterInitialNotificationDate() === null) {
                        $lines[] = 'Warning!! Missing initial notification date';
                    }
                }
            }
        } catch (\Exception $e) {
            $lines[] = sprintf('Exception!! Msg; %s', $e->getMessage());
        }

        return $lines;
    }

    private function header(Policy $policy)
    {
        $lines = [];
        $lines[] = '';
        $lines[] = sprintf(
            '%s (<a href="%s">Admin</a>)',
            $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId(),
            $this->routerService->generateUrl('admin_policy', ['id' => $policy->getId()])
        );
        $lines[] = '---';

        return $lines;
    }

    private function failureStatusMessage(Policy $policy, $prefix, $date)
    {
        $message = sprintf(
            'Unexpected status %s %s',
            $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId(),
            $policy->getStatus()
        );

        if ($policy->hasPolicyOrUserBacsPaymentMethod()) {
            $bankAccount = $policy->getPolicyOrUserBacsBankAccount();
            $bacsStatus = $bankAccount ?
                $bankAccount->getMandateStatus() :
                'unknown';
            $message = sprintf('%s (Bacs Mandate Status: %s)', $message, $bacsStatus);
        }

        return $message;
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

    private function failurePaymentMessage(Policy $policy, $prefix, $date)
    {
        $totalPaid = $policy->getTotalSuccessfulPayments($date);
        $expectedPaid = $policy->getTotalExpectedPaidToDate($date);
        return sprintf(
            'Paid £%0.2f Expected £%0.2f',
            $totalPaid,
            $expectedPaid
        );
    }

    private function failureIptRateMessage(Policy $policy)
    {
        return sprintf(
            'Unexpected ipt rate %0.2f (Expected %0.2f)',
            $policy->getPremium()->getIptRate(),
            $policy->getCurrentIptRate($policy->getStart())
        );
    }

    private function failurePotValueMessage(Policy $policy)
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
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);

        $picsurePolicies = $policyRepo->findBy(
            ['picSureStatus' => ['$in' => [PhonePolicy::PICSURE_STATUS_APPROVED,
                                        PhonePolicy::PICSURE_STATUS_INVALID,
                                        PhonePolicy::PICSURE_STATUS_REJECTED]]]
        );

        foreach ($picsurePolicies as $policy) {
            /** @var PhonePolicy $policy */
            $files = $policy->getPolicyPicSureFiles();
            if (count($files) > 0) {
                $metadata = $files[0]->getMetadata();
                if (empty($metadata['picsure-status'])) {
                    $files[0]->addMetadata('picsure-status', $policy->getPicSureStatus());
                }
            }
        }
        $this->dm->flush();
    }

    private function resyncPicsureMetadata()
    {
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $filesRepo = $this->dm->getRepository(PicSureFile::class);
        $picsureFiles = $filesRepo->findAll();

        $picsurePolicies = $policyRepo->findBy(
            ['picSureStatus' => ['$in' => [PhonePolicy::PICSURE_STATUS_MANUAL,
                                        PhonePolicy::PICSURE_STATUS_APPROVED,
                                        PhonePolicy::PICSURE_STATUS_INVALID,
                                        PhonePolicy::PICSURE_STATUS_REJECTED]]]
        );

        foreach ($picsurePolicies as $policy) {
            /** @var PhonePolicy $policy */
            $files = $policy->getPolicyPicSureFiles();
            if (count($files) > 0) {
                foreach ($files as $file) {
                    $result = $this->s3->getObject(array(
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
                                $this->logger->error(sprintf(
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
        $this->dm->flush();
    }
}
