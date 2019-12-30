<?php
namespace AppBundle\Service;

use AppBundle\Document\Payment\PotRewardPayment;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\SalvaPhonePolicyRepository;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use DOMDocument;
use DOMXPath;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Cashback;
use AppBundle\Document\User;
use AppBundle\Document\Feature;
use AppBundle\Document\File\SalvaPolicyFile;
use AppBundle\Document\File\SalvaPaymentFile;
use AppBundle\Classes\Salva;
use AppBundle\Classes\NoOp;

class SalvaExportService
{
    use CurrencyTrait;

    const SCHEMA_POLICY_IMPORT = 'policy/import/policyImportV1.xsd';
    const SCHEMA_POLICY_TERMINATE = 'policy/termination/policyTerminationV1.xsd';

    const S3_BUCKET = 'salva.so-sure.com';

    const CANCELLED_REPLACE = 'new_cover_to_be_issued';
    const CANCELLED_UNPAID = 'debt';
    const CANCELLED_ACTUAL_FRAUD = 'annulment';
    const CANCELLED_SUSPECTED_FRAUD = 'annulment';
    const CANCELLED_USER_REQUESTED = 'withdrawal_client';
    const CANCELLED_COOLOFF = 'annulment';
    const CANCELLED_BADRISK = 'claim';
    const CANCELLED_OTHER = 'other';
    const CANCELLED_DISPOSSESSION = 'dispossession';
    const CANCELLED_WRECKAGE = 'wreckage';

    const KEY_POLICY_ACTION = 'salva:policyid:action';

    const QUEUE_CREATED = 'created';
    const QUEUE_UPDATED = 'updated';
    const QUEUE_CANCELLED = 'cancelled';

    // Policy status is Terminated policy. Only issued policy can be terminated.
    const ERROR_POLICY_TERMINATED = 'webservice.constraint.C1406-05';

    // Policy number must be unique. Another policy with given policy number already
    const ERROR_POLICY_EXISTS = 'policy.constraint.C1044-11';

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $baseUrl;

    /** @var string */
    protected $username;

    /** @var string */
    protected $password;

    /** @var string */
    protected $rootDir;

    /** @var \Predis\Client */
    protected $redis;

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $environment;

    /** @var FeatureService */
    protected $featureService;

    public function setEnvironment($environment)
    {
        $this->environment = $environment;
    }

    public function setDm($dm)
    {
        $this->dm = $dm;
    }

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $baseUrl
     * @param string          $username
     * @param string          $password
     * @param string          $rootDir
     * @param \Predis\Client  $redis
     * @param S3Client        $s3
     * @param string          $environment
     * @param FeatureService  $featureService
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        $baseUrl,
        $username,
        $password,
        $rootDir,
        \Predis\Client $redis,
        S3Client $s3,
        $environment,
        FeatureService $featureService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->baseUrl = $baseUrl;
        $this->username = $username;
        $this->password = $password;
        $this->rootDir = $rootDir;
        $this->redis = $redis;
        $this->s3 = $s3;
        $this->environment = $environment;
        $this->featureService = $featureService;
    }

    public function transformPolicy(SalvaPhonePolicy $policy = null, $version = null)
    {
        if ($policy) {
            if (!$policy->getPremiumInstallmentCount()) {
                //\Doctrine\Common\Util\Debug::dump($policy);
                throw new \Exception('Invalid policy payment');
            }
            $startDate = $policy->getSalvaStartDate($version);
            if ($version) {
                $payments = $policy->getPaymentsForSalvaVersions()[$version];

                $status = SalvaPhonePolicy::STATUS_CANCELLED;
                $premiumPaid = $policy->getPremiumPaid($payments);
                $brokerPaid = $policy->getTotalCommissionPaid($payments);
                $terminationDate = $policy->getSalvaTerminationDate($version) ?
                    $policy->getSalvaTerminationDate($version) :
                    null;
            } else {
                $allPayments = $policy->getPaymentsForSalvaVersions(false);

                $status = $policy->getStatus();
                $premiumPaid = $policy->getRemainingPremiumPaid($allPayments);
                $brokerPaid = $policy->getRemainingTotalCommissionPaid($allPayments);
                $terminationDate = $policy->getStatus() == SalvaPhonePolicy::STATUS_CANCELLED ?
                    $policy->getEnd():
                    null;
            }

            $data = [
                $policy->getSalvaPolicyNumber($version), // 0
                $status,
                $this->adjustDate($policy->getSalvaStartDate($version)),
                $this->adjustDate($policy->getStaticEnd()),
                $terminationDate ? $this->adjustDate($terminationDate) : '',
                $policy->getCompany() ? $policy->getCompany()->getId() : $policy->getUser()->getId(), // 5
                $policy->getCompany() ? '' : $policy->getUser()->getFirstName(),
                $policy->getCompany() ? $policy->getCompany()->getName() : $policy->getUser()->getLastName(),
                $policy->getPhone()->getMake(),
                $policy->getPhone()->getModel(),
                $policy->getPhone()->getMemory(), // 10
                $policy->getImei(),
                $policy->getPhone()->getInitialPrice(),
                $policy->getPremiumInstallmentCount(),
                $policy->getPremiumInstallmentPrice(),
                $policy->getTotalPremiumPrice($version), // 15
                $premiumPaid,
                $policy->getTotalIpt($version),
                $policy->getTotalBrokerFee($version),
                $brokerPaid,
                $policy->getSalvaConnections($version), // 20
                $policy->getSalvaPotValue($version),
                $policy->getSalvaPromoPotValue($version)
            ];
        } else {
            $data = [
                'PolicyNumber', // 0
                'Status',
                'InceptionDate',
                'EndDate',
                'TerminationDate',
                'CustomerId', // 5
                'FirstName',
                'LastName',
                'Make',
                'Model',
                'Memory', // 10
                'Imei',
                'EstimatedPhonePrice',
                'NumberInstallments',
                'InstallmentAmount',
                'TotalPremium', // 15
                'PaidPremium',
                'TotalIpt',
                'TotalBrokerFee',
                'PaidBrokerFee',
                'NumberConnections', // 20
                'PotValue',
                'MarkertingPotValue',
            ];
        }

        return $data;
    }

    protected function formatLine($data)
    {
        return sprintf('"%s"', implode('","', $this->escapeQuotes($data)));
    }

    public function exportPolicies($s3, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
            $date->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));
        }

        $lines = [];
        $paidPremium = 0;
        $paidBrokerFee = 0;
        /** @var SalvaPhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(SalvaPhonePolicy::class);
        $lines[] = sprintf("%s", $this->formatLine($this->transformPolicy(null)));
        /** @var SalvaPhonePolicy $policy */
        foreach ($repo->getAllPoliciesForExport($date, $this->environment) as $policy) {
            foreach ($policy->getSalvaPolicyNumbers() as $version => $versionDate) {
                $data = $this->transformPolicy($policy, $version);
                $paidPremium += $data[16];
                $paidBrokerFee += $data[19];
                $lines[] =  sprintf("%s", $this->formatLine($data));
            }
            $data = $this->transformPolicy($policy);
            $paidPremium += $data[16];
            $paidBrokerFee += $data[19];
            $lines[] =  sprintf("%s", $this->formatLine($data));
        }

        if ($s3) {
            $filename = sprintf(
                'policies-export-%d-%02d-%s.csv',
                $date->format('Y'),
                $date->format('m'),
                $date->format('U')
            );
            $key = $this->uploadS3(implode("\n", $lines), $filename, 'policies', $date->format('Y'));

            $file = new SalvaPolicyFile();
            $file->setBucket(self::S3_BUCKET);
            $file->setKey($key);
            $file->setDate($date);
            $file->addMetadata('paidPremium', $paidPremium);
            $file->addMetadata('paidBrokerFee', $paidBrokerFee);
            $this->dm->persist($file);
            $this->dm->flush();
        }

        return $lines;
    }

    public function exportPayments($uploadS3, \DateTime $date = null, SalvaPaymentFile $paymentFile = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
            $date->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));
        }

        $dailyTransaction = [];
        $lines = [];
        $total = 0;
        $numPayments = 0;
        /** @var PaymentRepository $repo */
        $repo = $this->dm->getRepository(Payment::class);
        $lines[] = sprintf("%s", $this->formatLine($this->transformPayment(null)));
        $payments = $repo->getAllPaymentsForExport($date, false, Policy::TYPE_SALVA_PHONE);
        /** @var Payment $payment */
        foreach ($payments as $payment) {
            if (!$payment->getPolicy()) {
                throw new \Exception(sprintf('Payment %s is missing policy', $payment->getId()));
            }

            // For prod, skip invalid policies
            if ($this->environment == 'prod' && !$payment->getPolicy()->isValidPolicy()) {
                continue;
            }
            $data = $this->transformPayment($payment);
            $total += $data[2];
            $numPayments++;
            $lines[] = sprintf("%s", $this->formatLine($data));
            if (!isset($dailyTransaction[$payment->getDate()->format('Ymd')])) {
                $dailyTransaction[$payment->getDate()->format('Ymd')] = 0;
            }
            $dailyTransaction[$payment->getDate()->format('Ymd')] += $payment->getAmount();
        }

        if ($paymentFile) {
            $paymentFile->addMetadata('total', $total);
            $paymentFile->addMetadata('numPayments', $numPayments);
            $paymentFile->setDailyTransaction($dailyTransaction);
            $this->dm->flush();
        } elseif ($uploadS3) {
            $filename = sprintf(
                'payments-export-%d-%02d-%s.csv',
                $date->format('Y'),
                $date->format('m'),
                $date->format('U')
            );
            $key = $this->uploadS3(implode("\n", $lines), $filename, 'payments', $date->format('Y'));

            $file = new SalvaPaymentFile();
            $file->setBucket(self::S3_BUCKET);
            $file->setKey($key);
            $file->setDate($date);
            $file->addMetadata('total', $total);
            $file->addMetadata('numPayments', $numPayments);
            $file->setDailyTransaction($dailyTransaction);
            $this->dm->persist($file);
            $this->dm->flush();
        }

        return $lines;
    }

    public function exportClaims($s3, \DateTime $date = null, $days = null)
    {
        NoOp::ignore($days);
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
            $date->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));
        }

        $lines = [];
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $lines[] =  sprintf('%s', $this->formatLine($this->transformClaim(null)));
        /** @var Claim $claim */
        foreach ($repo->getAllClaimsForExport(Policy::TYPE_SALVA_PHONE) as $claim) {
            // For prod, skip invalid policies
            if ($this->environment == 'prod' && !$claim->getPolicy()->isValidPolicy()) {
                continue;
            }

            // There's a bit of a timing issue with claims - if a fnol claim gets a claim number via the claims
            // portal (transitioning to in-review), however, this occurs before the claims excel sheet is imported,
            // then this claim would be exported to salva, which breaks their import process
            $financialAmounts = $claim->getReservedValue() + $claim->getTotalIncurred() +
                $claim->getClaimHandlingFees();
            if ($claim->getStatus() == Claim::STATUS_INREVIEW && $this->areEqualToTwoDp(0, $financialAmounts)) {
                continue;
            }

            $data = $this->transformClaim($claim);
            $lines[] = sprintf('%s', $this->formatLine($data));
        }

        if ($s3) {
            $filename = sprintf(
                'claims-export-%d-%02d-%02d-%s.csv',
                $date->format('Y'),
                $date->format('m'),
                $date->format('d'),
                $date->format('U')
            );
            $this->uploadS3(implode("\n", $lines), $filename, 'claims', $date->format('Y'));
        }

        return $lines;
    }

    public function exportRenewals($s3, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
            $date->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));
        }

        $lines = [];
        /** @var SalvaPhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(SalvaPhonePolicy::class);
        $lines[] =  sprintf('%s', $this->formatLine($this->transformRenewal(null)));
        /** @var SalvaPhonePolicy $policy */
        foreach ($repo->getAllExpiredPoliciesForExport($date, $this->environment) as $policy) {
            // For prod, skip invalid policies
            if ($this->environment == 'prod' && !$policy->isValidPolicy()) {
                continue;
            }
            // We want policies that had a pot discount, so skip those with a 0 pot value
            if (!$this->greaterThanZero($policy->getPotValue())) {
                continue;
            }
            $data = $this->transformRenewal($policy);
            $lines[] = sprintf("%s", $this->formatLine($data));
        }

        if ($s3) {
            $filename = sprintf(
                'renewals-export-%d-%02d-%02d-%s.csv',
                $date->format('Y'),
                $date->format('m'),
                $date->format('d'),
                $date->format('U')
            );
            $this->uploadS3(implode("\n", $lines), $filename, 'renewals', $date->format('Y'));
        }

        return $lines;
    }

    public function uploadS3($data, $filename, $type, $year)
    {
        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);
        file_put_contents($tmpFile, $data);
        $s3Key = sprintf('%s/%s/%s/%s', $this->environment, $type, $year, $filename);

        $result = $this->s3->putObject(array(
            'Bucket' => self::S3_BUCKET,
            'Key'    => $s3Key,
            'SourceFile' => $tmpFile,
        ));

        unlink($tmpFile);

        return $s3Key;
    }

    public function transformPayment(Payment $payment = null)
    {
        if ($payment) {
            if (!$payment->isSuccess()) {
                throw new \Exception('Invalid payment');
            }
            /** @var SalvaPhonePolicy $policy */
            $policy = $payment->getPolicy();
            if (!$policy) {
                throw new \Exception('Invalid policy');
            }
            $data = [
                $policy->getSalvaPolicyNumberByDate($payment->getDate()),
                $this->adjustDate($payment->getDate()),
                $this->toTwoDp($payment->getAmount()),
                $payment->getNotes() ? $payment->getNotes() : '',
                $this->toTwoDp($payment->getTotalCommission()),
            ];
        } else {
            $data = [
                'PolicyNumber',
                'PaymentDate',
                'PaymentAmount',
                'Notes',
                'BrokerFee',
            ];
        }

        return $data;
    }

    public function transformClaim(Claim $claim = null)
    {
        if ($claim) {
            /** @var SalvaPhonePolicy $policy */
            $policy = $claim->getPolicy();
            $data = [
                $policy->getSalvaPolicyNumberByDate($claim->getRecordedDate()),
                $claim->getNumber(),
                $claim->getStatus(),
                $claim->getNotificationDate() ?
                    $this->adjustDate($claim->getNotificationDate()) :
                    '',
                $claim->getLossDate() ?
                    $this->adjustDate($claim->getLossDate()) :
                    '',
                $claim->getType(),
                $claim->getDescription(),
                $this->toTwoDp($claim->getExcess()),
                $this->toTwoDp($claim->getReservedValue()),
                $this->toTwoDp($claim->getTotalIncurred()),
                $this->toTwoDp($claim->getClaimHandlingFees()),
                $claim->getReplacementReceivedDate() ?
                    $this->adjustDate($claim->getReplacementReceivedDate()) :
                    '',
                $claim->getReplacementPhone() ?
                    $claim->getReplacementPhone()->getMake() :
                    '',
                $claim->getReplacementPhone() ?
                    $claim->getReplacementPhone()->getModel() :
                    '',
                $claim->getReplacementImei(),
                $claim->getHandlingTeam() ? $claim->getHandlingTeam() : '',
                $claim->getUnderwriterLastUpdated() ?
                    $this->adjustDate($claim->getUnderwriterLastUpdated()) :
                    ''
            ];
        } else {
            $data = [
                'PolicyNumber',
                'ClaimNumber',
                'Status',
                'NotificationDate',
                'EventDate',
                'EventType',
                'EventDescription',
                'Excess',
                'ReservedAmount',
                'CostOfClaim',
                'HandlingCost',
                'ReplacementDeliveryDate',
                'ReplacementMake',
                'ReplacementModel',
                'ReplacementImei',
                'Claims Handler',
                'Last Updated',
            ];
        }

        return $data;
    }

    public function transformRenewal(SalvaPhonePolicy $policy = null)
    {
        if ($policy) {
            /** @var SalvaPhonePolicy $nextPolicy */
            $nextPolicy = $policy->getNextPolicy();
            $potReward = null;
            foreach ($policy->getAllPayments() as $payment) {
                if ($payment instanceof PotRewardPayment && $payment->isSuccess()) {
                    $potReward = $payment;
                }
            }
            $incurredDate = $potReward ? $potReward->getDate() : $policy->getStaticEnd();
            $data = [
                $policy->getSalvaPolicyNumber(),
                $this->toTwoDp($policy->getPotValue()),
                $this->toTwoDp($policy->getStandardPotValue()),
                //$this->adjustDate($policy->getStaticEnd(), false),
                $this->adjustDate($incurredDate, false),
                $policy->getCashback() ?
                    sprintf('cashback - %s', $policy->getCashback()->getDisplayableStatus()) :
                    'discount',
                ($policy->getCashback() && $policy->getCashback()->getStatus() == Cashback::STATUS_PAID) ?
                    $this->adjustDate($policy->getCashback()->getDate(), false) :
                    '',
                $policy->isRenewed() ? $nextPolicy->getSalvaPolicyNumber() : '',
                $policy->isRenewed() ? $nextPolicy->getPremium()->getMonthlyPremiumPrice() : '',
                $policy->isRenewed() ? $nextPolicy->getPremium()->getAnnualDiscount() : '',
                $policy->isRenewed() ? $nextPolicy->getPremium()->getMonthlyDiscount() : '',
                $policy->isRenewed() ?
                    $nextPolicy->getPremium()->getAdjustedStandardMonthlyPremiumPrice() :
                    '',
            ];
        } else {
            $data = [
                'InitialPolicyNumber',
                'RewardPot',
                'RewardPotSalva',
                //'RewardPotIncurredDate',
                'RewardPotIncurredDate',
                'RewardPotType',
                'CashbackPaidDate',
                'RenewalPolicyNumber',
                'RenewalPolicyMonthlyPremiumExDiscount',
                'RenewalPolicyDiscount',
                'RenewalPolicyDiscountPerMonth',
                'RenewalPolicyMonthlyPremiumIncDiscount',
            ];
        }

        return $data;
    }

    public function sendPolicy(SalvaPhonePolicy $phonePolicy)
    {
        $xml = $this->createXml($phonePolicy);
        $this->logger->info($xml);
        if (!$this->validate($xml, self::SCHEMA_POLICY_IMPORT)) {
            throw new \Exception('Failed to validate policy');
        }
        $response = $this->send($xml, self::SCHEMA_POLICY_IMPORT);
        $this->logger->info($response);

        // occasionally it seems like a create response is lost. When we then try to re-create
        // there is an already a created response, which we can safely ignore
        $responseId = $this->getResponseId($response, [self::ERROR_POLICY_EXISTS]);

        $phonePolicy->addSalvaPolicyResults($responseId, SalvaPhonePolicy::RESULT_TYPE_CREATE, [
            'ss_phone_base_tariff' => $phonePolicy->getTotalGwp()
        ]);
        $phonePolicy->setSalvaStatus(SalvaPhonePolicy::SALVA_STATUS_ACTIVE);
        $this->dm->flush();

        return $responseId;
    }

    public function cancelPolicy(SalvaPhonePolicy $phonePolicy, $reason = null, $version = null)
    {
        $date = $phonePolicy->getEnd();

        // We should only bump the salva version if we're replacing a policy
        if ($reason && $reason == self::CANCELLED_REPLACE) {
            // latest start date same as previous termination date
            if ($version) {
                $date = $phonePolicy->getSalvaStartDate($version + 1);
            } else {
                $date = $phonePolicy->getLatestSalvaStartDate();
            }
        }

        if (!$reason) {
            if ($phonePolicy->getCancelledReason() == SalvaPhonePolicy::CANCELLED_UNPAID) {
                $reason = self::CANCELLED_UNPAID;
            } elseif ($phonePolicy->getCancelledReason() == SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD) {
                $reason = self::CANCELLED_ACTUAL_FRAUD;
            } elseif ($phonePolicy->getCancelledReason() == SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD) {
                $reason = self::CANCELLED_SUSPECTED_FRAUD;
            } elseif ($phonePolicy->getCancelledReason() == SalvaPhonePolicy::CANCELLED_USER_REQUESTED) {
                $reason = self::CANCELLED_USER_REQUESTED;
            } elseif ($phonePolicy->getCancelledReason() == SalvaPhonePolicy::CANCELLED_UPGRADE) {
                // upgrade is just for our purposes
                $reason = self::CANCELLED_USER_REQUESTED;
            } elseif ($phonePolicy->getCancelledReason() == SalvaPhonePolicy::CANCELLED_COOLOFF) {
                $reason = self::CANCELLED_COOLOFF;
            } elseif ($phonePolicy->getCancelledReason() == SalvaPhonePolicy::CANCELLED_BADRISK) {
                $reason = self::CANCELLED_BADRISK;
            } elseif ($phonePolicy->getCancelledReason() == SalvaPhonePolicy::CANCELLED_DISPOSSESSION) {
                $reason = self::CANCELLED_DISPOSSESSION ;
            } elseif ($phonePolicy->getCancelledReason() == SalvaPhonePolicy::CANCELLED_WRECKAGE) {
                $reason = self::CANCELLED_WRECKAGE;
            } else {
                $reason = self::CANCELLED_OTHER;
            }
        }

        $cancelXml = $this->cancelXml($phonePolicy, $reason, $date, $version);
        $xml = $cancelXml['xml'];
        $this->logger->info($xml);
        if (!$this->validate($xml, self::SCHEMA_POLICY_TERMINATE)) {
            throw new \Exception('Failed to validate cancel policy');
        }
        $response = $this->send($xml, self::SCHEMA_POLICY_TERMINATE);
        $this->logger->info($response);

        // occasionally it seems like a terminate response is lost. When we then try to re-terminate
        // there is an already terminated response, which we can safely ignore
        $responseId = $this->getResponseId($response, [self::ERROR_POLICY_TERMINATED]);
        $phonePolicy->addSalvaPolicyResults($responseId, SalvaPhonePolicy::RESULT_TYPE_CANCEL, [
            'usedFinalPremium' => $cancelXml['usedFinalPremium'],
        ]);
        if ($phonePolicy->getSalvaStatus() == SalvaPhonePolicy::SALVA_STATUS_PENDING_CANCELLED) {
            $phonePolicy->setSalvaStatus(SalvaPhonePolicy::SALVA_STATUS_CANCELLED);
            $this->dm->flush();
        } elseif ($phonePolicy->getSalvaStatus() == SalvaPhonePolicy::SALVA_STATUS_PENDING_REPLACEMENT_CANCEL) {
            $phonePolicy->setSalvaStatus(SalvaPhonePolicy::SALVA_STATUS_PENDING_REPLACEMENT_CREATE);
            $this->dm->flush();
        } else {
            $this->logger->warning(sprintf(
                'Unknown salva status %s for policy %s',
                $phonePolicy->getSalvaStatus(),
                $phonePolicy->getId()
            ));
        }

        return $responseId;
    }

    public function processPolicy(SalvaPhonePolicy $policy, $action, $cancelReason = null)
    {
        if ($policy->getSalvaStatus() == SalvaPhonePolicy::SALVA_STATUS_WAIT_CANCELLED) {
            throw new \UnexpectedValueException(sprintf(
                'Unable to process policy %s (wait status prior to cancellation?). Requeuing',
                $policy->getId()
            ));
        }

        if ($action == self::QUEUE_CREATED) {
            $this->sendPolicy($policy);
        } elseif ($action == self::QUEUE_CANCELLED) {
            $this->cancelPolicy($policy, $cancelReason);
        } elseif ($action == self::QUEUE_UPDATED) {
            if ($this->featureService->isEnabled(Feature::FEATURE_SALVA_POLICY_UPDATE)) {
                $this->updatePolicyDirect($policy);
            } else {
                $this->updatePolicyCancelCreate($policy);
            }
        } else {
            throw new \Exception(sprintf(
                'Unknown action %s for policyId: %s',
                $action,
                $policy->getId()
            ));
        }
        $this->dm->flush();
    }

    public function updatePolicyDirect(SalvaPhonePolicy $phonePolicy)
    {
        $phonePolicy->setSalvaStatus(SalvaPhonePolicy::SALVA_STATUS_PENDING_UPDATE);
        $this->dm->flush();

        $xml = $this->updateXml($phonePolicy);
        $this->logger->info($xml);
        if (!$this->validate($xml, self::SCHEMA_POLICY_IMPORT)) {
            throw new \Exception('Failed to validate policy');
        }
        $response = $this->send($xml, self::SCHEMA_POLICY_IMPORT);
        $this->logger->info($response);
        $responseId = $this->getResponseId($response);
        $phonePolicy->addSalvaPolicyResults($responseId, SalvaPhonePolicy::RESULT_TYPE_UPDATE, [
            'ss_phone_base_tariff' => $phonePolicy->getTotalGwp()
        ]);
        $phonePolicy->setSalvaStatus(SalvaPhonePolicy::SALVA_STATUS_ACTIVE);
        $this->dm->flush();

        return $responseId;
    }

    public function updatePolicyCancelCreate(Policy $policy)
    {
        // If exception thrown in the middle of an update, log and avoid re-queueing policy actions
        try {
            /** @var SalvaPhonePolicy $salvaPhonePolicy */
            $salvaPhonePolicy = $policy;
            $this->incrementPolicyNumber($salvaPhonePolicy);

            $this->queueMessage($policy->getId(), self::QUEUE_CANCELLED, 0, self::CANCELLED_REPLACE);
            $this->queueMessage($policy->getId(), self::QUEUE_CREATED, 0);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Error running QUEUE_UPDATED for policy %s', $policy->getId()),
                ['exception' => $e]
            );
        }
    }

    public function process($max, $prefix = null)
    {
        $count = 0;
        while ($count < $max) {
            /** @var Policy $policy */
            $policy = null;
            $action = null;
            $cancelReason = null;
            try {
                $queueItem = $this->redis->lpop(self::KEY_POLICY_ACTION);
                if (!$queueItem) {
                    return $count;
                }
                $data = unserialize($queueItem);

                if (!isset($data['policyId']) || !$data['policyId'] || !isset($data['action']) || !$data['action']) {
                    throw new \Exception(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                /** @var SalvaPhonePolicyRepository $repo */
                $repo = $this->dm->getRepository(SalvaPhonePolicy::class);
                /** @var SalvaPhonePolicy $policy */
                $policy = $repo->find($data['policyId']);
                $action = $data['action'];
                if (isset($data['cancel_reason'])) {
                    $cancelReason = $data['cancel_reason'];
                }
                if (!$policy) {
                    throw new \Exception(sprintf('Unable to find policyId: %s', $data['policyId']));
                }
                if (!$policy->isValidPolicy($prefix)) {
                    throw new \Exception(sprintf('Invalid policy - policyId: %s', $data['policyId']));
                }

                $this->processPolicy($policy, $action, $cancelReason);

                $count = $count + 1;
            } catch (\Exception $e) {
                if ($policy && $action) {
                    $queued = false;
                    if (isset($data['retryAttempts']) && $data['retryAttempts'] >= 0) {
                        // 20 minute attempts
                        if ($data['retryAttempts'] < 20) {
                            $this->queueMessage(
                                $policy->getId(),
                                $action,
                                $data['retryAttempts'] + 1,
                                $cancelReason
                            );
                            $queued = true;
                        }
                    } else {
                        $this->queue($policy, $action);
                        $queued = true;
                    }
                    $this->logger->error(sprintf(
                        'Error sending policy %s (%s) to salva (requeued: %s). Ex: %s',
                        $policy->getId(),
                        $action,
                        $queued ? 'Yes' : 'No',
                        $e->getMessage()
                    ));
                } else {
                    $this->logger->error(sprintf(
                        'Error sending policy (Unknown) to salva (requeued). Ex: %s',
                        $e->getMessage()
                    ));
                }

                throw $e;
            }
        }
        
        return $count;
    }

    public function queuePolicy(Policy $policy, $action)
    {
        $repo = $this->dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $salvaPolicy */
        $salvaPolicy = $repo->find($policy->getId());
        if (!$salvaPolicy) {
            return false;
        }

        return $this->queue($salvaPolicy, $action);
    }

    public function queue(SalvaPhonePolicy $policy, $action, $retryAttempts = 0)
    {
        if (!in_array($action, [self::QUEUE_CANCELLED, self::QUEUE_CREATED, self::QUEUE_UPDATED])) {
            throw new \Exception(sprintf('Unknown queue action %s', $action));
        }

        // For production, only process valid policies (e.g. not policies with @so-sure.com)
        if ($this->environment == "prod" && !$policy->isValidPolicy()) {
            return false;
        }

        $this->queueMessage($policy->getId(), $action, $retryAttempts);

        return true;
    }

    public function incrementPolicyNumber(SalvaPhonePolicy $policy, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $date->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));
        $policy->incrementSalvaPolicyNumber($date);
        $policy->setSalvaStatus(SalvaPhonePolicy::SALVA_STATUS_PENDING_REPLACEMENT_CANCEL);
        $this->dm->flush();
    }

    private function queueMessage($id, $action, $retryAttempts, $cancelReason = null)
    {
        $data = ['policyId' => $id, 'action' => $action, 'retryAttempts' => $retryAttempts];
        if ($cancelReason) {
            $data['cancel_reason'] = $cancelReason;
        }
        $this->redis->rpush(self::KEY_POLICY_ACTION, serialize($data));
    }

    public function clearQueue()
    {
        $this->redis->del([self::KEY_POLICY_ACTION]);
    }

    public function getQueueData($max)
    {
        return $this->redis->lrange(self::KEY_POLICY_ACTION, 0, $max);
    }

    protected function getResponseId($xml, $allowedErrors = [])
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOBLANKS);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ns1', "http://sims.salva.ee/service/schema/v1");
        $xpath->registerNamespace('ns2', "http://sims.salva.ee/service/schema/policy/v1");

        $elementList = $xpath->query('//ns2:policy/ns2:recordId');
        foreach ($elementList as $element) {
            return $element->nodeValue;
        }

        $elementList = $xpath->query('//ns1:errorResponse/ns1:errorList/ns1:constraint');
        foreach ($elementList as $element) {
            $allowedError = in_array($element->getAttribute('ns1:code'), $allowedErrors);
            $errMsg = sprintf(
                "Error sending policy. Allowed Error: %s Response: %s : %s",
                $allowedError ? 'Yes' : 'No',
                $element->getAttribute('ns1:code'),
                $element->nodeValue
            );
            $this->logger->error($errMsg);

            if (!$allowedError) {
                throw new \Exception($errMsg);
            }

            return $element->nodeValue;
        }

        throw new \Exception('Unable to get response');
    }

    public function adjustDate(\DateTime $date, $datetime = true)
    {
        $clonedDate = clone $date;
        $clonedDate->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));

        if ($datetime) {
            return $clonedDate->format("Y-m-d\TH:i:00");
        } else {
            return $clonedDate->format("Y-m-d");
        }
    }

    public function cancelXml(SalvaPhonePolicy $phonePolicy, $reason, $date, $version = null)
    {
        if (!$version) {
            if ($reason == self::CANCELLED_REPLACE) {
                // Make sure policy was incremented prior to calling
                $version = $phonePolicy->getLatestSalvaPolicyNumberVersion() - 1;
                if (!isset($phonePolicy->getPaymentsForSalvaVersions()[$version])) {
                    throw new \Exception(sprintf(
                        'Missing version %s for salva. Was version incremented prior to cancellation?',
                        $version
                    ));
                }
            } else {
                    $version = $phonePolicy->getLatestSalvaPolicyNumberVersion();
            }
        }

        $policyNumber = $phonePolicy->getSalvaPolicyNumber($version);
        if (isset($phonePolicy->getPaymentsForSalvaVersions()[$version])) {
            $payments = $phonePolicy->getPaymentsForSalvaVersions()[$version];
        } else {
            $payments = $phonePolicy->getPayments();
        }

        $dom = new DOMDocument('1.0', 'UTF-8');

        $root = $dom->createElement('n1:serviceRequest');
        $dom->appendChild($root);
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:n1',
            'http://sims.salva.ee/service/schema/policy/termination/v1'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:n2',
            'http://sims.salva.ee/service/schema/v1'
        );
        $root->appendChild($dom->createElement('n1:policyNo', $policyNumber));
        $root->appendChild($dom->createElement('n1:terminationReasonCode', $reason));

        // Full refund logic: terminationTime = insurancePeriodStart-1 minute & usedFinalPremium=0
        if ($phonePolicy->isCancelledFullRefund()) {
            $date = $phonePolicy->getStart();
            if ($reason && $reason == self::CANCELLED_REPLACE) {
                $date = $phonePolicy->getSalvaStartDate($version);
            }

            $date = $date->sub(new \DateInterval('PT1M'));
        }

        $root->appendChild($dom->createElement(
            'n1:terminationTime',
            $this->adjustDate($date)
        ));

        if ($phonePolicy->isCancelledFullRefund()) {
            // Full refund logic: terminationTime = insurancePeriodStart-1 minute & usedFinalPremium=0
            $usedPremium = 0;
        } elseif ($reason == self::CANCELLED_REPLACE) {
            $usedPremium = $phonePolicy->getUsedGwp($version, true);
        } else {
            $usedPremium = $phonePolicy->getUsedGwp(null, false);
        }

        $usedFinalPremium = $dom->createElement('n1:usedFinalPremium', $usedPremium);
        $usedFinalPremium->setAttribute('n2:currency', 'GBP');
        $root->appendChild($usedFinalPremium);

        return ['xml' => $dom->saveXML(), 'usedFinalPremium' => $usedPremium];
    }

    public function createXml(SalvaPhonePolicy $phonePolicy)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        $root = $dom->createElement('ns3:serviceRequest');
        $dom->appendChild($root);
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:ns3',
            'http://sims.salva.ee/service/schema/policy/import/v1'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:ns2',
            'http://sims.salva.ee/service/schema/policy/v1'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:ns1',
            'http://sims.salva.ee/service/schema/v1'
        );
        $root->setAttribute('ns3:mode', 'policy');
        $root->setAttribute('ns3:includeInvoiceRows', 'true');

        $policy = $dom->createElement('ns3:policy');
        $root->appendChild($policy);

        $policy->appendChild($dom->createElement('ns2:renewable', 'false'));
        $policy->appendChild($dom->createElement(
            'ns2:insurancePeriodStart',
            $this->adjustDate($phonePolicy->getLatestSalvaStartDate())
        ));

        $policy->appendChild($dom->createElement(
            'ns2:insurancePeriodEnd',
            $this->adjustDate($phonePolicy->getEnd())
        ));
        $policy->appendChild($dom->createElement(
            'ns2:paymentsPerYearCode',
            $phonePolicy->getPaymentsPerYearCode()
        ));
        $policy->appendChild($dom->createElement('ns2:issuerUser', 'so_sure'));
        $policy->appendChild($dom->createElement('ns2:deliveryModeCode', 'undefined'));
        $policy->appendChild($dom->createElement('ns2:policyNo', $phonePolicy->getSalvaPolicyNumber()));
        // If policy is completely paid, then no need to include the firstDueDate
        if ($phonePolicy->getSalvaFirstDueDate()) {
            $policy->appendChild($dom->createElement(
                'ns2:firstDueDate',
                $this->adjustDate($phonePolicy->getSalvaFirstDueDate(), false)
            ));
        }

        $policyCustomers = $dom->createElement('ns2:policyCustomers');
        $policy->appendChild($policyCustomers);
        $policyCustomers->appendChild($this->createCustomer($dom, $phonePolicy, 'policyholder'));

        $insuredObjects = $dom->createElement('ns2:insuredObjects');
        $policy->appendChild($insuredObjects);
        $insuredObject = $dom->createElement('ns2:insuredObject');
        $insuredObjects->appendChild($insuredObject);
        $insuredObject->appendChild($dom->createElement('ns2:productObjectCode', 'ss_phone'));
        $insuredObject->appendChild(
            $dom->createElement('ns2:tariffDate', $this->adjustDate($phonePolicy->getIssueDate()))
        );

        $objectCustomers = $dom->createElement('ns2:objectCustomers');
        $insuredObject->appendChild($objectCustomers);
        $objectCustomers->appendChild($this->createCustomer($dom, $phonePolicy, 'insured_person'));

        $objectFields = $dom->createElement('ns2:objectFields');
        $insuredObject->appendChild($objectFields);
        $phone = $phonePolicy->getPhone();
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_make', $phone->getMake()));
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_model', $phone->getModel()));
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_memory', $phone->getMemory()));
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_imei', $phonePolicy->getImei()));
        $objectFields->appendChild($this->createObjectFieldMoney($dom, 'ss_phone_value', $phone->getInitialPrice()));

        $tariff = $phonePolicy->getTotalGwp();
        $objectFields->appendChild($this->createObjectFieldMoney($dom, 'ss_phone_base_tariff', $tariff));

        return $dom->saveXML();
    }

    public function updateXml(SalvaPhonePolicy $phonePolicy)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        $root = $dom->createElement('ns3:serviceRequest');
        $dom->appendChild($root);
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:ns3',
            'http://sims.salva.ee/service/schema/policy/import/v1'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:ns2',
            'http://sims.salva.ee/service/schema/policy/v1'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:ns1',
            'http://sims.salva.ee/service/schema/v1'
        );
        $root->setAttribute('ns3:mode', 'policy_change');
        $root->setAttribute('ns3:includeInvoiceRows', 'true');

        $policy = $dom->createElement('ns3:policy');
        $root->appendChild($policy);

        $policy->appendChild($dom->createElement('ns2:renewable', 'false'));
        $policy->appendChild($dom->createElement(
            'ns2:insurancePeriodStart',
            $this->adjustDate($phonePolicy->getLatestSalvaStartDate())
        ));

        $policy->appendChild($dom->createElement(
            'ns2:insurancePeriodEnd',
            $this->adjustDate($phonePolicy->getEnd())
        ));
        $policy->appendChild($dom->createElement(
            'ns2:paymentsPerYearCode',
            $phonePolicy->getPaymentsPerYearCode()
        ));
        $policy->appendChild($dom->createElement('ns2:issuerUser', 'so_sure'));
        $policy->appendChild($dom->createElement('ns2:deliveryModeCode', 'undefined'));
        $policy->appendChild($dom->createElement('ns2:policyNo', $phonePolicy->getSalvaPolicyNumber()));
        // If policy is completely paid, then no need to include the firstDueDate
        if ($phonePolicy->getSalvaFirstDueDate()) {
            $policy->appendChild($dom->createElement(
                'ns2:firstDueDate',
                $this->adjustDate($phonePolicy->getSalvaFirstDueDate(), false)
            ));
        }

        $policyCustomers = $dom->createElement('ns2:policyCustomers');
        $policy->appendChild($policyCustomers);
        $policyCustomers->appendChild($this->createCustomer($dom, $phonePolicy, 'policyholder'));

        $insuredObjects = $dom->createElement('ns2:insuredObjects');
        $policy->appendChild($insuredObjects);
        $insuredObject = $dom->createElement('ns2:insuredObject');
        $insuredObjects->appendChild($insuredObject);
        $insuredObject->appendChild($dom->createElement('ns2:productObjectCode', 'ss_phone'));

        $objectCustomers = $dom->createElement('ns2:objectCustomers');
        $insuredObject->appendChild($objectCustomers);
        $objectCustomers->appendChild($this->createCustomer($dom, $phonePolicy, 'insured_person'));

        $objectFields = $dom->createElement('ns2:objectFields');
        $insuredObject->appendChild($objectFields);
        $phone = $phonePolicy->getPhone();
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_make', $phone->getMake()));
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_model', $phone->getModel()));
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_memory', $phone->getMemory()));
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_imei', $phonePolicy->getImei()));
        $objectFields->appendChild($this->createObjectFieldMoney($dom, 'ss_phone_value', $phone->getInitialPrice()));

        $tariff = $phonePolicy->getTotalGwp();
        $objectFields->appendChild($this->createObjectFieldMoney($dom, 'ss_phone_base_tariff', $tariff));

        return $dom->saveXML();
    }

    private function createObjectFieldText($dom, $code, $value)
    {
        $objectField = $dom->createElement('ns2:objectField');
        $objectField->setAttribute('ns2:fieldCode', $code);
        $objectField->setAttribute('ns2:fieldTypeCode', 'string');

        $textValue = $dom->createElement('ns2:textValue', $value);
        $objectField->appendChild($textValue);

        return $objectField;
    }

    private function createObjectFieldMoney($dom, $code, $value)
    {
        $objectField = $dom->createElement('ns2:objectField');
        $objectField->setAttribute('ns2:fieldCode', $code);
        $objectField->setAttribute('ns2:fieldTypeCode', 'money');

        $amountValue = $dom->createElement('ns2:amountValue', $value);
        $amountValue->setAttribute('ns1:currency', 'GBP');
        $objectField->appendChild($amountValue);

        return $objectField;
    }

    private function createCustomer($dom, Policy $policy, $role)
    {
        $customer = $dom->createElement('ns2:customer');
        $customer->setAttribute('ns2:role', $role);

        $company = $policy->getCompany();
        $user = $policy->getUser();

        if ($company) {
            $customer->appendChild($dom->createElement('ns2:code', $company->getId()));
            $customer->appendChild($dom->createElement('ns2:name', $company->getName()));
            $customer->appendChild($dom->createElement('ns2:countryCode', 'GB'));
            $customer->appendChild($dom->createElement('ns2:personTypeCode', 'corporate'));
        } else {
            $customer->appendChild($dom->createElement('ns2:code', $user->getId()));
            $customer->appendChild($dom->createElement('ns2:name', $user->getLastName()));
            $customer->appendChild($dom->createElement('ns2:firstName', $user->getFirstName()));
            $customer->appendChild($dom->createElement('ns2:countryCode', 'GB'));
            $customer->appendChild($dom->createElement('ns2:personTypeCode', 'private'));
        }

        return $customer;
    }

    public function validate($xml, $schemaType)
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOBLANKS); // Or load if filename required
        $schema = sprintf(
            "%s/../src/AppBundle/Resources/salva/service-schemas/%s",
            $this->rootDir,
            $schemaType
        );

        return $dom->schemaValidate($schema);
    }
    
    public function send($xml, $schema)
    {
        $client = new Client();
        $url = sprintf("%s/service/xmlService", $this->baseUrl);
        $res = $client->request('POST', $url, [
            'body' => $xml,
            'auth' => [$this->username, $this->password]
        ]);
        $body = (string) $res->getBody();

        if (!$this->validate($body, $schema)) {
            throw new \InvalidArgumentException("unable to validate response");
        }

        return $body;
    }

    private function escapeQuotes($string)
    {
        return str_replace('"', '\"', $string);
    }
}
