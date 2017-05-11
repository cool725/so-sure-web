<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use AppBundle\Classes\DaviesClaim;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\DaviesFile;
use Doctrine\ODM\MongoDB\DocumentManager;
use VasilDakov\Postcode\Postcode;

class DaviesService
{
    use CurrencyTrait;
    use DateTrait;

    const PROCESSED_FOLDER = 'processed';
    const UNPROCESSED_FOLDER = 'unprocessed';
    const FAILED_FOLDER = 'failed';

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var ExcelService */
    protected $excel;

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $bucket;

    /** @var string */
    protected $path;

    /** @var ClaimsService */
    protected $claimsService;

    protected $mailer;

    private $errors = [];

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param ExcelService    $excel
     * @param S3Client        $s3
     * @param ClaimsService   $claimsService
     * @param                 $environment
     * @param                 $mailer
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        ExcelService $excel,
        S3Client $s3,
        ClaimsService $claimsService,
        $environment,
        $mailer
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->excel = $excel;
        $this->s3 = $s3;
        $this->bucket = 'ops.so-sure.com';
        $this->path = sprintf('claims-report/%s', $environment);
        $this->claimsService = $claimsService;
        $this->mailer = $mailer;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function clearErrors()
    {
        $this->errors = [];
    }

    public function import($sheetName)
    {
        $lines = [];
        $keys = $this->listS3();
        foreach ($keys as $key) {
            $lines[] = sprintf('Processing %s/%s', $this->path, $key);
            $processed = false;
            try {
                $emailFile = $this->downloadEmail($key);
                if ($excelFile = $this->extractExcelFromEmail($emailFile)) {
                    $claims = $this->parseExcel($excelFile, $sheetName);
                    $processed = $this->saveClaims($key, $claims);
                } else {
                    throw new \Exception('Unable to locate excel file in email message');
                }
            } catch (\Exception $e) {
                $processed = false;
                $this->logger->error(sprintf('Error processing %s. Moving to failed. Ex: %s', $key, $e->getMessage()));
            }

            if ($processed) {
                $this->moveS3($key, self::PROCESSED_FOLDER);
                $lines[] = sprintf('Successfully imported %s/%s and moved to processed folder', $this->path, $key);
            } else {
                $this->moveS3($key, self::FAILED_FOLDER);
                $lines[] = sprintf('Failed to import %s/%s and moved to failed folder', $this->path, $key);
            }

            $this->claimsDailyEmail();

            if (file_exists($excelFile)) {
                unlink($excelFile);
            }
            if (file_exists($emailFile)) {
                unlink($emailFile);
            }
        }

        return $lines;
    }

    public function importFile($file, $sheetName)
    {
        $lines = [];
        $lines[] = sprintf('Processing %s', $file);
        $processed = false;
        try {
            $claims = $this->parseExcel($file, $sheetName);
            $processed = $this->saveClaims($file, $claims);
        } catch (\Exception $e) {
            $processed = false;
            $this->logger->error(sprintf('Error processing %s. Ex: %s', $file, $e->getMessage()));
        }

        if ($processed) {
            $lines[] = sprintf('Successfully imported %s', $file);
        } else {
            $lines[] = sprintf('Failed to import %s', $file);
        }
        $this->claimsDailyEmail();

        return $lines;
    }

    /**
     * @return array
     */
    public function listS3()
    {
        $iterator = $this->s3->getIterator('ListObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => sprintf('%s/unprocessed/', $this->path),
        ]);

        $keys = [];
        foreach ($iterator as $object) {
            if ($object['Size'] > 0 &&
                stripos($object['Key'], 'AMAZON_SES_SETUP_NOTIFICATION') === false) {
                $keys[] = $object['Key'];
            }
        }

        return $keys;
    }

    public function moveS3($sourceKey, $folder)
    {
        $now = new \DateTime();
        $destKey = str_replace(
            sprintf('/%s/', self::UNPROCESSED_FOLDER),
            sprintf('/%s/%d/', $folder, $now->format('Y')),
            $sourceKey
        );
        $this->s3->copyObject([
            'Bucket' => $this->bucket,
            'CopySource' => sprintf("%s/%s", $this->bucket, $sourceKey),
            'Key' => $destKey,
        ]);
        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $sourceKey,
        ]);

        $file = new DaviesFile();
        $file->setBucket($this->bucket);
        $file->setKey($destKey);
        $file->setSuccess($folder == self::PROCESSED_FOLDER);
        $this->dm->persist($file);
        $this->dm->flush();
    }

    public function generateTempFile()
    {
        $tempFile = tempnam(sys_get_temp_dir(), "davies");

        return $tempFile;
    }

    public function downloadEmail($s3Key)
    {
        $tempFile = $this->generateTempFile();

        $result = $this->s3->getObject(array(
            'Bucket' => $this->bucket,
            'Key'    => $s3Key,
            'SaveAs' => $tempFile,
        ));

        return $tempFile;
    }

    /**
     * @param string $filename
     *
     * @return string Excel tmp file
     */
    public function extractExcelFromEmail($filename)
    {
        $excelFile = null;
        $fileTxt = implode("", file($filename));
        $mime = mailparse_msg_parse_file($filename);
        try {
            $structure = mailparse_msg_get_structure($mime);
            foreach ($structure as $element) {
                $mimePart = mailparse_msg_get_part($mime, $element);
                $bodyParts = mailparse_msg_get_part_data($mimePart);
                if (isset($bodyParts['content-type'])) {
                    try {
                        $fileExtension = $this->excel->getFileExtension($bodyParts['content-type']);
                        $testFile = sprintf("%s.%s", $this->generateTempFile(), $fileExtension);
                        if ($file = fopen($testFile, "wb")) {
                            fputs($file, mailparse_msg_extract_part_file($mimePart, $filename, null));
                            fclose($file);
                            $excelFile = $testFile;
                        }
                    } catch (\Exception $e) {
                        $this->logger->debug(
                            sprintf('Skipping email attachment %s', $bodyParts['content-type']),
                            ['exception' => $e]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            $excelFile = null;
            $this->logger->error(sprintf("Unable to parse email. Ex: %s", $e->getMessage()));
        }
        mailparse_msg_free($mime);

        return $excelFile;
    }

    public function parseExcel($filename, $sheetName)
    {
        $tempFile = $this->generateTempFile();
        $this->excel->convertToCsv($filename, $tempFile, $sheetName);
        $lines = array_map('str_getcsv', file($tempFile));
        unlink($tempFile);

        $claims = [];
        $row = -1;
        $columns = -1;
        foreach ($lines as $line) {
            $row++;
            try {
                $columns = DaviesClaim::getColumnsFromSheetName($sheetName);
                // There may be additional blank columns that need to be ignored
                $line = array_slice($line, 0, $columns);
                $claim = DaviesClaim::create($line, $columns);
                // If the claim doesn't have correct data, just ignore
                if ($claim) {
                    $claims[] = $claim;
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf("Error parsing line %s. Ex: %s", json_encode($line), $e->getMessage()));

                throw $e;
            }
        }

        if (count($claims) == 0) {
            throw new \Exception(sprintf('Unable to find any data to process in file'));
        }

        return $claims;
    }

    public function saveClaims($key, array $daviesClaims)
    {
        $success = true;
        $claims = [];
        foreach ($daviesClaims as $daviesClaim) {
            if (isset($claims[$daviesClaim->policyNumber]) && $claims[$daviesClaim->policyNumber]) {
                throw new \Exception(sprintf(
                    'There are multiple open claims against policy %s',
                    $daviesClaim->policyNumber
                ));
            }
            $claims[$daviesClaim->policyNumber] = $daviesClaim->isOpen();
        }
        foreach ($daviesClaims as $daviesClaim) {
            try {
                $this->saveClaim($daviesClaim);
            } catch (\Exception $e) {
                $success = false;
                $this->errors[$daviesClaim->claimNumber][] = $e->getMessage();
                $this->logger->error(sprintf('Error processing file %s', $key), ['exception' => $e]);
            }
        }

        return $success;
    }

    public function saveClaim($daviesClaim, $claim = null)
    {
        if (!$claim) {
            $repo = $this->dm->getRepository(Claim::class);
            $claim = $repo->findOneBy(['number' => $daviesClaim->claimNumber]);
        }

        if (!$claim) {
            throw new \Exception(sprintf('Unable to locate claim %s in db', $daviesClaim->claimNumber));
        }

        $this->validateClaimDetails($claim, $daviesClaim);

        if ($claim->getType() != $daviesClaim->getClaimType()) {
            throw new \Exception(sprintf('Claims type does not match for claim %s', $daviesClaim->claimNumber));
        }
        if ($daviesClaim->getClaimStatus()) {
            $claim->setStatus($daviesClaim->getClaimStatus());
        } elseif ($daviesClaim->replacementImei && $claim->getStatus() == Claim::STATUS_INREVIEW) {
            // If there's a replacement IMEI, the claim has definitely been approved
            $claim->setStatus(Claim::STATUS_APPROVED);
        }

        $claim->setDaviesStatus($daviesClaim->status);

        $claim->setExcess($daviesClaim->excess);
        $claim->setIncurred($daviesClaim->incurred);
        $claim->setClaimHandlingFees($daviesClaim->handlingFees);
        $claim->setReservedValue($daviesClaim->reserved);

        $claim->setAccessories($daviesClaim->accessories);
        $claim->setUnauthorizedCalls($daviesClaim->unauthorizedCalls);
        $claim->setPhoneReplacementCost($daviesClaim->phoneReplacementCost);
        $claim->setTransactionFees($daviesClaim->transactionFees);

        // Probably not going to be returned, but maybe one day will be able to map Davies/Brighstar data
        if ($replacementPhone = $this->getReplacementPhone($daviesClaim)) {
            $claim->setReplacementPhone($replacementPhone);
        }

        if (in_array($claim->getStatus(), [Claim::STATUS_APPROVED, Claim::STATUS_SETTLED])
            && !$claim->getApprovedDate()) {
            // for claims without replacement date, the replacement should have occurred yesterday
            // for cases where its been forgotten, the business day should be 1 day prior to the received date
            $yesterday = new \DateTime();
            if ($daviesClaim->replacementReceivedDate) {
                $yesterday = clone $daviesClaim->replacementReceivedDate;
            }
            $yesterday = $this->subBusinessDays($yesterday, 1);

            $claim->setApprovedDate($yesterday);
        }

        $claim->setReplacementImei($daviesClaim->replacementImei);
        $claim->setReplacementReceivedDate($daviesClaim->replacementReceivedDate);
        $claim->setReplacementPhoneDetails($daviesClaim->getReplacementPhoneDetails());

        $claim->setDescription($daviesClaim->lossDescription);
        $claim->setLocation($daviesClaim->location);

        $claim->setClosedDate($daviesClaim->dateClosed);
        $claim->setCreatedDate($daviesClaim->dateCreated);
        $claim->setNotificationDate($daviesClaim->notificationDate);
        $claim->setLossDate($daviesClaim->lossDate);

        $claim->setShippingAddress($daviesClaim->shippingAddress);

        $this->updatePolicy($claim, $daviesClaim);
        $this->dm->flush();

        $this->postValidateClaimDetails($claim, $daviesClaim);

        $this->claimsService->processClaim($claim);
    }

    public function validateClaimDetails(Claim $claim, DaviesClaim $daviesClaim)
    {
        if ($claim->getPolicy()->getPolicyNumber() != $daviesClaim->policyNumber) {
            throw new \Exception(sprintf(
                'Claim %s does not match policy number %s',
                $daviesClaim->claimNumber,
                $daviesClaim->policyNumber
            ));
        }

        if ($daviesClaim->replacementImei && in_array($daviesClaim->getClaimStatus(), [
            Claim::STATUS_DECLINED,
            Claim::STATUS_WITHDRAWN
        ])) {
            throw new \Exception(sprintf(
                'Claim %s has a replacement IMEI Number, yet has a withdrawn/declined status',
                $daviesClaim->claimNumber
            ));
        }

        $now = new \DateTime();
        if ($daviesClaim->isOpen() || ($daviesClaim->dateClosed && $daviesClaim->dateClosed->diff($now)->days < 5)) {
            similar_text($claim->getPolicy()->getUser()->getName(), $daviesClaim->insuredName, $percent);

            if ($percent < 30) {
                throw new \Exception(sprintf(
                    'Claim %s: %s does not match expected insuredName %s (match %0.1f)',
                    $daviesClaim->claimNumber,
                    $daviesClaim->insuredName,
                    $claim->getPolicy()->getUser()->getName(),
                    $percent
                ));
            } elseif ($percent < 75) {
                $msg = sprintf(
                    'Claim %s: %s does not match expected insuredName %s (match %0.1f)',
                    $daviesClaim->claimNumber,
                    $daviesClaim->insuredName,
                    $claim->getPolicy()->getUser()->getName(),
                    $percent
                );
                $this->logger->warning($msg);
                $this->errors[$daviesClaim->claimNumber][] = $msg;
            }

            if ($daviesClaim->riskPostCode && !$this->postcodeCompare(
                $claim->getPolicy()->getUser()->getBillingAddress()->getPostCode(),
                $daviesClaim->riskPostCode
            )) {
                $msg = sprintf(
                    'Claim %s: %s does not match expected postcode %s',
                    $daviesClaim->claimNumber,
                    $daviesClaim->riskPostCode,
                    $claim->getPolicy()->getUser()->getBillingAddress()->getPostCode()
                );
                $this->logger->warning($msg);
                $this->errors[$daviesClaim->claimNumber][] = $msg;
            }
        }

        // Open Non-Warranty Claims are expected to either have a total incurred value or a reserved value
        if ($daviesClaim->isOpen() && !$daviesClaim->isClaimWarranty() &&
            $this->areEqualToTwoDp($daviesClaim->getIncurred(), 0) &&
            $this->areEqualToTwoDp($daviesClaim->getReserved(), 0)) {
            $msg = sprintf('Claim %s does not have a reserved value', $daviesClaim->claimNumber);
            $this->logger->warning($msg);
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }

        if ($daviesClaim->isExcessValueCorrect() === false) {
            $msg = sprintf(
                'Claim %s does not have the correct excess value. Expected %0.2f Actual %0.2f for %s/%s',
                $daviesClaim->claimNumber,
                $daviesClaim->getExpectedExcess(),
                $daviesClaim->excess,
                $daviesClaim->getClaimType(),
                $daviesClaim->getClaimStatus()
            );
            $this->logger->warning($msg);
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }

        if ($daviesClaim->isIncurredValueCorrect() === false) {
            $msg = sprintf(
                'Claim %s does not have the correct incurred value. Expected %0.2f Actual %0.2f',
                $daviesClaim->claimNumber,
                $daviesClaim->getExpectedIncurred(),
                $daviesClaim->incurred
            );
            $this->logger->warning($msg);
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }

        // We should always validate Recipero Fee if the fee is present or if the claim is closed
        if (($daviesClaim->isClosed(true) || $daviesClaim->reciperoFee > 0) &&
            !$this->areEqualToTwoDp($claim->totalChargesWithVat(), $daviesClaim->reciperoFee)) {
            $msg = sprintf(
                'Claim %s does not have the correct recipero fee. Expected £%0.2f Actual £%0.2f',
                $daviesClaim->claimNumber,
                $claim->totalChargesWithVat(),
                $daviesClaim->reciperoFee
            );
            $this->logger->warning($msg);
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }

        if ($daviesClaim->isClosed(true) && $daviesClaim->reserved > 0) {
            $msg = sprintf(
                'Claim %s is closed, yet still has a reserve fee.',
                $daviesClaim->claimNumber
            );
            $this->logger->warning($msg);
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }

        if (!$claim->getReplacementReceivedDate() && $daviesClaim->replacementReceivedDate) {
            // We should be notified the next day when a replacement device is delivered
            // so we can follow up with our customer. Unlikely to occur.
            $ago = new \DateTime();
            $ago = $this->subBusinessDays($ago, 1);

            if ($daviesClaim->replacementReceivedDate < $ago) {
                $msg = sprintf(
                    'Claim %s has a delayed replacement date (%s) which is more than 1 business day ago (%s)',
                    $daviesClaim->claimNumber,
                    $daviesClaim->replacementReceivedDate->format(\DateTime::ATOM),
                    $ago->format(\DateTime::ATOM)
                );
                $this->logger->warning($msg);
                $this->errors[$daviesClaim->claimNumber][] = $msg;
            }
        }
    }

    public function postValidateClaimDetails(Claim $claim, DaviesClaim $daviesClaim)
    {
        if ($claim->getApprovedDate() && $claim->getReplacementReceivedDate() &&
            $claim->getApprovedDate() > $claim->getReplacementReceivedDate()) {
            $msg = sprintf(
                'Claim %s has an approved date (%s) more recent than the received date (%s)',
                $daviesClaim->claimNumber,
                $claim->getApprovedDate()->format(\DateTime::ATOM),
                $claim->getReplacementReceivedDate()->format(\DateTime::ATOM)
            );
            $this->logger->warning($msg);
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }
    }

    private function postcodeCompare($postcodeA, $postcodeB)
    {
        $postcodeA = new Postcode($postcodeA);
        $postcodeB = new Postcode($postcodeB);
        /*
        if (!$postcodeA|| !$postcodeB) {
            return false;
        }*/

        return $postcodeA->normalise() === $postcodeB->normalise();
    }

    public function getReplacementPhone(DaviesClaim $daviesClaim)
    {
        \AppBundle\Classes\NoOp::ignore([$daviesClaim]);
        $repo = $this->dm->getRepository(Phone::class);
        // TODO: Can we get the brightstar product numbers?
        // $phone = $repo->findOneBy(['brightstar_number' => $daviesClaim->brightstarProductNumber]);

        // TODO: If not brightstar, should be able to somehow parse these....
        // $daviesClaim->replacementMake $daviesClaim->replacementModel
        $phone = null;

        return $phone;
    }

    public function updatePolicy(Claim $claim, DaviesClaim $daviesClaim)
    {
        $policy = $claim->getPolicy();
        // Closed claims should not replace the imei as if there are multiple claims
        // for a policy it will trigger a salva policy update
        if ($claim->isOpen()) {
            // We've replaced their phone with a new imei number
            if ($claim->getReplacementImei() &&
                $claim->getReplacementImei() != $policy->getImei()) {
                // Imei has changed, but we can't change their policy premium, which is fixed
                $policy->setImei($claim->getReplacementImei());
                // If phone has been updated (unlikely at the moment)
                if ($claim->getReplacementPhone()) {
                    $policy->setPhone($claim->getReplacementPhone());
                }
                $this->mailer->sendTemplate(
                    sprintf('Verify Policy %s IMEI Update', $policy->getPolicyNumber()),
                    'tech@so-sure.com',
                    'AppBundle:Email:davies/checkPhone.html.twig',
                    ['policy' => $policy, 'daviesClaim' => $daviesClaim]
                );
            }
        }

        if ($claim->getReplacementImei() && !$claim->getReplacementReceivedDate()) {
            if (!$policy->getImeiReplacementDate()) {
                throw new \Exception(sprintf(
                    'Expected imei replacement date for policy %s',
                    $policy->getId()
                ));
            }

            $now = new \DateTime();
            // no set time of day when the report is sent, so for this, just assume the day, not time
            $replacementDay = $this->startOfDay(clone $policy->getImeiReplacementDate());
            $twoBusinessDays = $this->addBusinessDays($replacementDay, 2);
            if ($now >= $twoBusinessDays) {
                $msg = sprintf(
                    'Claim %s is missing a replacement recevied date (expected 2 days after imei replacement)',
                    $daviesClaim->claimNumber
                );
                $this->logger->warning($msg);
                $this->errors[$daviesClaim->claimNumber][] = $msg;
            }
        }
    }

    public function claimsDailyEmail()
    {
        $fileRepo = $this->dm->getRepository(DaviesFile::class);
        $latestFiles = $fileRepo->findBy([], ['created' => 'desc'], 1);
        $latestFile = count($latestFiles) > 0 ? $latestFiles[0] : null;

        $successFiles = $fileRepo->findBy(['success' => true], ['created' => 'desc'], 1);
        $successFile = count($successFiles) > 0 ? $successFiles[0] : null;

        $claimsRepo = $this->dm->getRepository(Claim::class);
        $claims = $claimsRepo->findOutstanding();

        $this->mailer->sendTemplate(
            sprintf('Daily Claims'),
            'tech@so-sure.com',
            'AppBundle:Email:davies/dailyEmail.html.twig',
            [
                'claims' => $claims,
                'latestFile' => $latestFile,
                'successFile' => $successFile,
                'errors' => $this->errors
            ]
        );

        return count($claims);
    }
}
