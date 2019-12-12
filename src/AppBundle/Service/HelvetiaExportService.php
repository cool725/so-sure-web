<?php

namespace AppBundle\Service;

use AppBundle\Document\Payment\PotRewardPayment;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\HelvetiaPhonePolicyRepository;
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
use AppBundle\Classes\Salva;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Cashback;
use AppBundle\Document\User;
use AppBundle\Document\Feature;

/**
 * Exports data for helvetia underwriter.
 */
class HelvetiaExportService
{
    const S3_BUCKET = 'helvetia.so-sure.com';

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $environment;

    /**
     * Injects the service's dependencies.
     * @param DocumentManager $dm          is used to access the database.
     * @param LoggerInterface $logger      is used to log developer messages.
     * @param S3Client        $s3          is used to upload the created exports to s3.
     * @param string          $environment is the environment that the service is operating in.
     */
    public function __construct(DocumentManager  $dm, LoggerInterface $logger, S3Client $s3, $environment) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->s3 = $s3;
        $this->environment = $environment;
    }

    /**
     * Generates a big and beautiful pictures.
     * @param 
    public function exportPolicies($s3, \DateTime $date = null)
    {
        $date = $date ?: new \DateTime();
        $date->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));
        /** @var HelvetiaPhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(HelvetiaPhonePolicy::class);

        $paidPremium = 0;
        $paidBrokerFee = 0;
        $lines = [];
        $lines[] = sprintf("%s", $this->formatLine($this->transformPolicy(null)));
        /** @var SalvaPhonePolicy $policy */
        foreach ($repo->getAllPoliciesForExport($date, $this->environment) as $policy) {
            $data = $this->transformPolicy($policy);
            $paidPremium += $data[16];
            $paidBrokerFee += $data[19];
            $lines[] = CsvHelper::line(
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
                $policy->getTotalPremiumPrice(), // 15
                $policy->getPremiumPaid(),
                $policy->getTotalIpt(),
                $policy->getTotalBrokerFee(),
                $policy->getBrokerFeePaid(),
                $policy->getSalvaPotValue(),
                $policy->getSalvaPromoPotValue()
            );
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
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
            $date->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));
        }

        $lines = [];
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $lines[] =  sprintf('%s', $this->formatLine($this->transformClaim(null)));
        foreach ($repo->getAllClaimsForExport(Policy::TYPE_SALVA_PHONE) as $claim) {
            /** @var Claim $claim */
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
}
