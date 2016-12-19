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

    public function import()
    {
        $lines = [];
        $keys = $this->listS3();
        foreach ($keys as $key) {
            $lines[] = sprintf('Processing %s/%s', $this->path, $key);
            $processed = false;
            try {
                $emailFile = $this->downloadEmail($key);
                if ($excelFile = $this->extractExcelFromEmail($emailFile)) {
                    $claims = $this->parseExcel($excelFile);
                    $processed = $this->saveClaims($key, $claims);
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

            if (file_exists($excelFile)) {
                unlink($excelFile);
            }
            if (file_exists($emailFile)) {
                unlink($emailFile);
            }
        }

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
        // TODO: Should split into date folders
        $destKey = str_replace(sprintf('/%s/', self::UNPROCESSED_FOLDER), sprintf('/%s/', $folder), $sourceKey);
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
                if (isset($bodyParts['content-type']) &&
                    $bodyParts['content-type'] == "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet") {
                    $excelFile = sprintf("%s.xlsx", $this->generateTempFile());
                    if ($file = fopen($excelFile, "wb")) {
                        fputs($file, mailparse_msg_extract_part_file($mimePart, $filename, null));
                        fclose($file);
                    } else {
                        $excelFile = null;
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

    public function parseExcel($filename)
    {
        $tempFile = $this->generateTempFile();
        $this->excel->convertToCsv($filename, $tempFile);
        $lines = array_map('str_getcsv', file($tempFile));
        unlink($tempFile);

        $claims = [];
        $row = -1;
        $columns = -1;
        foreach ($lines as $line) {
            $row++;
            if ($row == 0) {
                $columns = count(array_slice(array_filter($line), 0));
                continue;
            }
            try {
                $line = array_slice($line, 0, $columns);
                $claim = DaviesClaim::create($line);
                // If the claim is a blank line, just ignore
                if ($claim) {
                    $claims[] = $claim;
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf("Error parsing line %s. Ex: %s", json_encode($line), $e->getMessage()));

                throw $e;
            }
        }

        return $claims;
    }

    public function saveClaims($key, array $daviesClaims)
    {
        $success = true;
        foreach ($daviesClaims as $daviesClaim) {
            try {
                $repo = $this->dm->getRepository(Claim::class);
                $claim = $repo->findOneBy(['number' => $daviesClaim->claimNumber]);
                if (!$claim) {
                    throw new \Exception(sprintf('Unable to locate claim %s', $daviesClaim->claimNumber));
                }

                $this->validateClaimDetails($claim, $daviesClaim);

                if ($claim->getType() != $daviesClaim->getClaimType()) {
                    throw new \Exception(sprintf('Claims type does not match for claim %s', $daviesClaim->claimNumber));
                }
                if ($daviesClaim->getClaimStatus()) {
                    $claim->setStatus($daviesClaim->getClaimStatus());
                }
                $claim->setDaviesStatus($daviesClaim->status);

                $claim->setExcess($daviesClaim->excess);
                $claim->setIncurred($daviesClaim->incurred);
                $claim->setClaimHandlingFees($daviesClaim->claimHandlingFees);
                $claim->getReservedValue($daviesClaim->reserved);

                $claim->setReplacementPhone($this->getReplacementPhone($daviesClaim));
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

                $this->claimsService->processClaim($claim);
            } catch (\Exception $e) {
                $success = false;
                $this->logger->error(sprintf('Error processing file %s', $key), ['exception' => $e]);
            }
        }

        return $success;
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

        similar_text($claim->getPolicy()->getUser()->getName(), $daviesClaim->insuredName, $percent);

        if ($percent < 30) {
            throw new \Exception(sprintf(
                'Claim %s: %s does not match expected insuredName %s (match %s)',
                $daviesClaim->claimNumber,
                $daviesClaim->insuredName,
                $claim->getPolicy()->getUser()->getName(),
                $percent
            ));
        } elseif ($percent < 80) {
            $this->logger->warning(sprintf(
                'Claim %s: %s does not match expected insuredName %s (match %s)',
                $daviesClaim->claimNumber,
                $daviesClaim->insuredName,
                $claim->getPolicy()->getUser()->getName(),
                $percent
            ));
        }

        if (!$this->postcodeCompare(
            $claim->getPolicy()->getUser()->getBillingAddress()->getPostCode(),
            $daviesClaim->riskPostCode
        )) {
            $this->logger->warning(sprintf(
                'Claim %s: %s does not match expected postcode %s',
                $daviesClaim->claimNumber,
                $daviesClaim->riskPostCode,
                $claim->getPolicy()->getUser()->getBillingAddress()->getPostCode()
            ));
        }

        // Open Non-Warranty Claims are expected to either have a total incurred value or a reserved value
        if ($daviesClaim->isOpen() && !$daviesClaim->isClaimWarrantyOrExtended() &&
            $this->areEqualToTwoDp($daviesClaim->getIncurred(), 0) &&
            $this->areEqualToTwoDp($daviesClaim->getReserved(), 0)) {
            $this->mailer->sendTemplate(
                sprintf('Claim %s does not have a reserved value', $daviesClaim->claimNumber),
                'tech@so-sure.com',
                'AppBundle:Email:davies/missingReserve.html.twig',
                ['claim' => $claim, 'daviesClaim' => $daviesClaim]
            );
        }
    }

    private function postcodeCompare($postcodeA, $postcodeB)
    {
        return strtolower(str_replace(' ', '', $postcodeA)) == strtolower(str_replace(' ', '', $postcodeB));
    }

    public function getReplacementPhone(DaviesClaim $daviesClaim)
    {
        \AppBundle\Classes\NoOp::noOp([$daviesClaim]);
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
                $this->mailer->sendTemplate(
                    sprintf('Claim %s is missing a replacement recevied date', $daviesClaim->claimNumber),
                    'tech@so-sure.com',
                    'AppBundle:Email:davies/missingReplacementDate.html.twig',
                    ['policy' => $policy, 'daviesClaim' => $daviesClaim]
                );
            }
        }
    }

    public function claimsDailyEmail()
    {
        $fileRepo = $this->dm->getRepository(DaviesFile::class);
        $latestFiles = $fileRepo->findBy([], ['created' => 'desc']);
        $latestFile = count($latestFiles) > 0 ? $latestFiles[0] : null;

        $claimsRepo = $this->dm->getRepository(Claim::class);
        $claims = $claimsRepo->findOutstanding();

        $this->mailer->sendTemplate(
            sprintf('Daily Claims'),
            'tech@so-sure.com',
            'AppBundle:Email:davies/dailyEmail.html.twig',
            ['claims' => $claims, 'latestFile' => $latestFile]
        );

        return count($claims);
    }
}
