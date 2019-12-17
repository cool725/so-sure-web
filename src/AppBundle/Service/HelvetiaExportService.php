<?php

namespace AppBundle\Service;

use AppBundle\Helpers\CsvHelper;
use AppBundle\Document\Payment\PotRewardPayment;
use AppBundle\Document\File\HelvetiaPolicyFile;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\HelvetiaPhonePolicyRepository;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use DOMDocument;
use DOMXPath;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\HelvetiaPhonePolicy;
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
    use CurrencyTrait;

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
    public function __construct(DocumentManager  $dm, LoggerInterface $logger, S3Client $s3, $environment)
    {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->s3 = $s3;
        $this->environment = $environment;
    }

    /**
     * Generates the csv data to export to helvetia regarding policies.
     * @return array of comma seperated value lines.
     */
    public function generatePolicies()
    {
        /** @var HelvetiaPhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(HelvetiaPhonePolicy::class);
        $lines = [];
        $lines[] = CsvHelper::line(
            'PolicyNumber',
            'Status',
            'StartDate',
            'EndDate',
            'TerminationDate',
            'CustomerId',
            'FirstName',
            'LastName',
            'Make',
            'Model',
            'Memory',
            'Imei',
            'EstimatedPhonePrice',
            'NumberInstallments',
            'InstallmentAmount',
            'TotalPremium',
            'PaidPremium',
            'TotalIpt',
            'TotalBrokerFee',
            'PaidBrokerFee',
            'PotValue',
            'MarketingPotValue'
        );
        /** @var HelvetiaPhonePolicy $policy */
        foreach ($repo->getAllPoliciesForExport($this->environment) as $policy) {
            $lines[] = CsvHelper::line(
                $policy->getPolicyNumber(),
                $policy->getStatus(),
                $policy->getStart()->format("Ymd H:i"),
                $policy->getStaticEnd()->format("Ymd H:i"),
                $policy->getEnd()->format("Ymd H:i"),
                $policy->getCompany() ? $policy->getCompany()->getId() : $policy->getUser()->getId(),
                $policy->getCompany() ? '' : $policy->getUser()->getFirstName(),
                $policy->getCompany() ? $policy->getCompany()->getName() : $policy->getUser()->getLastName(),
                $policy->getPhone()->getMake(),
                $policy->getPhone()->getModel(),
                $policy->getPhone()->getMemory(),
                $policy->getImei(),
                $policy->getPhone()->getInitialPrice(),
                $policy->getPremiumInstallmentCount(),
                $policy->getPremiumInstallmentPrice(),
                $policy->getProRataPremium(),
                $policy->getPremiumPaid(),
                $policy->getProrataIpt(),
                $policy->getProRataBrokerFee(),
                $policy->getBrokerCommissionPaid(),
                $policy->getPotValue(),
                $policy->getPromoPotValue()
            );
        }
        return $lines;
    }

    /**
     * Generates the csv data to export to helvetia regarding payments.
     * @param \DateTime $date denotes the month that the payments should be exported for.
     * @return array of comma seperated value lines.
     */
    public function generatePayments(\DateTime $date)
    {
        /** @var PaymentRepository $repo */
        $repo = $this->dm->getRepository(Payment::class);
        $payments = $repo->getAllPaymentsForExport($date, false, Policy::TYPE_HELVETIA_PHONE);
        $lines = [];
        $lines[] = CsvHelper::line(
            'PolicyNumber',
            'Date',
            'Amount',
            'Notes',
            'BrokerFee'
        );
        /** @var Payment $payment */
        foreach ($payments as $payment) {
            $lines[] = CsvHelper::line(
                $payment->getPolicy()->getPolicyNumber(),
                $payment->getDate()->format("Ymd H:i"),
                $payment->getAmount(),
                $payment->getNotes(),
                $payment->getTotalCommission()
            );
        }
        return $lines;
    }

    /**
     * Generates the csv data to export to helvetia regarding claims.
     * @return array of comma sepeprated value lines.
     */
    public function generateClaims()
    {
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $lines = [];
        $lines[] = CsvHelper::line(
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
            'Last Updated'
        );
        /** @var Claim $claim */
        foreach ($repo->getAllClaimsForExport(Policy::TYPE_HELVETIA_PHONE) as $claim) {
            // There's a bit of a timing issue with claims - if a fnol claim gets a claim number via the claims
            // portal (transitioning to in-review), however, this occurs before the claims excel sheet is imported,
            // then this claim would be exported to salva, which breaks their import process
            $financialAmounts = $claim->getReservedValue() + $claim->getTotalIncurred() +
                $claim->getClaimHandlingFees();
            if ($claim->getStatus() == Claim::STATUS_INREVIEW && $this->areEqualToTwoDp(0, $financialAmounts)) {
                continue;
            }
            $lines[] = CsvHelper::line(
                $claim->getPolicy()->getPolicyNumber(),
                $claim->getNumber(),
                $claim->getStatus(),
                $claim->getNotificationDate()->format("Ymd H:i"),
                $claim->getLossDate()->format("Ymd H:i"),
                $claim->getType(),
                $claim->getDescription(),
                $claim->getExcess(),
                $claim->getReservedValue(),
                $claim->getTotalIncurred(),
                $claim->getClaimHandlingFees(),
                $claim->getReplacementReceivedDate(),
                $claim->getReplacementPhone() ? $claim->getReplacementPhone()->getMake() : '',
                $claim->getReplacementPhone() ? $claim->getReplacementPhone()->getModel() : '',
                $claim->getReplacementImei(),
                $claim->getHandlingTeam(),
                $claim->getUnderwriterLastUpdated() ? $claim->getUnderwriterLastUpdated()->format("Ymd H:i") : ''
            );
        }
        return $lines;
    }

    /**
     * Generates the csv data to export to helvetia regarding claims.
     * @return array of comma seperated value lines.
     */
    public function generateRenewals()
    {
        /** @var SalvaPhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(HelvetiaPhonePolicy::class);
        $lines = [];
        $lines[] = CsvHelper::line(
            'InitialPolicyNumber',
            'RewardPot',
            'RewardPotSalva',
            'RewardPotIncurredDate',
            'RewardPotType',
            'CashbackPaidDate',
            'RenewalPolicyNumber',
            'RenewalPolicyMonthlyPremiumExDiscount',
            'RenewalPolicyDiscount',
            'RenewalPolicyDiscountPerMonth',
            'RenewalPolicyMonthlyPremiumIncDiscount'
        );
        /** @var SalvaPhonePolicy $policy */
        foreach ($repo->getAllExpiredPoliciesForExport($this->environment) as $policy) {
            if (!$this->greaterThanZero($policy->getPotValue())) {
                continue;
            }
            $lines[] = CsvHelper::line(
                $policy->getPolicyNumber(),
                $policy->getPotValue(),
                $policy->getStandardPotValue(),
                $incurredDate->format("Ymd H:i"),
                $policy->getCashback() ?
                    sprintf('cashback - %s', $policy->getCashback()->getDisplayableStatus()) : 'discount',
                ($policy->getCashback() && $policy->getCashback()->getStatus() == Cashback::STATUS_PAID) ?
                    $policy->getCashback()->getDate() : '',
                $policy->isRenewed() ? $nextPolicy->getPolicyNumber() : '',
                $policy->isRenewed() ? $nextPolicy->getPremium()->getMonthlyPremiumPrice() : '',
                $policy->isRenewed() ? $nextPolicy->getPremium()->getAnnualDiscount() : '',
                $policy->isRenewed() ? $nextPolicy->getPremium()->getMonthlyDiscount() : '',
                $policy->isRenewed() ? $nextPolicy->getPremium()->getAdjustedStandardMonthlyPremiumPrice() : ''
            );
        }
        return $lines;
    }

    /**
     * Creates a new policy csv export and then uploads it to s3 in according with naming convention, and saves a
     * record of this upload in our database. This function takes no arguments as policy file generation is always up
     * to the present and so the present time is used for file naming.
     */
    public function uploadPolicies()
    {
        $date = new \DateTime();
        $data = $this->generatePolicies();
        $filename = sprintf(
            'policies-export-%d-%02d-%s.csv',
            $date->format('Y'),
            $date->format('m'),
            $date->format('U')
        );
        $this->uploadS3($data, $filename, 'policies', $date);
    }

    /**
     * Creates a new payment csv export and then uploads it to s3 in accordance with naming convention, and saves a
     * record of this upload in our database.
     * @param \DateTime $date is the date that the export is for and that the record will be branded with.
     */
    public function uploadPayments(\DateTime $date)
    {
        $data = $this->generatePayments($date);
        $filename = sprintf(
            'payments-export-%d-%02d-%s.csv',
            $date->format('Y'),
            $date->format('m'),
            $date->format('U')
        );
        $this->uploadS3($data, $filename, 'payments', $date);
    }

    /**
     * Creates a new claims csv export and then uploads it to s3 in according with naming convention, and saves a
     * record of this upload in our database. This function takes no arguments as claims file generation is always up
     * to the present and so the present time is used for file naming.
     */
    public function uploadClaims()
    {
        $date = new \DateTime();
        $data = $this->generateClaims();
        $filename = sprintf(
            'claims-export-%d-%02d-%s.csv',
            $date->format('Y'),
            $date->format('m'),
            $date->format('U')
        );
        $this->uploadS3($data, $filename, 'claims', $date);
    }

    /**
     * Creates a new renewals csv export and then uploads it to s3 in according with naming convention, and saves a
     * record of this upload in our database. This function takes no arguments as renewal file generation is always up
     * to the present and so the present time is used for file naming.
     */
    public function uploadRenewals()
    {
        $date = new \DateTime();
        $data = $this->generateRenewals();
        $filename = sprintf(
            'renewals-export-%d-%02d-%s.csv',
            $date->format('Y'),
            $date->format('m'),
            $date->format('U')
        );
        $this->uploadS3($data, $filename, 'renewals', $date);
    }

    /**
     * Uploads an array of csv lines onto s3 as a file.
     * @param array  $data     is the data to upload.
     * @param string $filename is the file name the file should have on s3.
     * @param string $type     determines the export type subfolder to place the file in.
     * @param int    $date     determines what yearly subfolder the file will be placed in, and the date it is marked
     *                         with in our database.
     * @return string the key to the file on s3.
     */
    private function uploadS3($data, $filename, $type, $date)
    {
        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);
        file_put_contents($tmpFile, implode("\r\n", $data));
        $s3Key = sprintf('%s/%s/%s/%s', $this->environment, $type, $date->format('Y'), $filename);
        $result = $this->s3->putObject(['Bucket' => self::S3_BUCKET, 'Key' => $s3Key, 'SourceFile' => $tmpFile]);
        unlink($tmpFile);
        $file = new HelvetiaPolicyFile();
        $file->setBucket(self::S3_BUCKET);
        $file->setKey($s3Key);
        $file->setDate($date);
        $this->dm->persist($file);
        $this->dm->flush();
        return $s3Key;
    }
}
