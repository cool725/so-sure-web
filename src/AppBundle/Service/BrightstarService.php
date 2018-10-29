<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use AppBundle\Classes\Brightstar;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\BrightstarFile;
use Doctrine\ODM\MongoDB\DocumentManager;
use VasilDakov\Postcode\Postcode;

class BrightstarService extends S3EmailService
{
    use CurrencyTrait;
    use DateTrait;

    protected $mailer;

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    public function processExcelData($key, $data)
    {
        return $this->checkClaims($key, $data);
    }

    public function postProcess()
    {
        $this->emailReport();
    }

    public function getNewS3File()
    {
        return new BrightstarFile();
    }

    public function getColumnsFromSheetName($sheetName)
    {
        return Brightstar::getColumnsFromSheetName($sheetName);
    }

    public function createLineObject($line, $columns)
    {
        return Brightstar::create($line, $columns);
    }

    public function checkClaims($key, array $data)
    {
        $success = true;
        foreach ($data as $brightstar) {
            try {
                $this->checkClaim($brightstar);
            } catch (\Exception $e) {
                $success = false;
                $this->errors[$brightstar->claimNumber][] = $e->getMessage();
                $this->logger->error(sprintf('Error processing file %s', $key), ['exception' => $e]);
            }
        }

        return $success;
    }

    public function checkClaim(Brightstar $brightstar, $claim = null)
    {
        if (!$claim) {
            $repo = $this->dm->getRepository(Claim::class);
            $claim = $repo->findOneBy(['number' => $brightstar->claimNumber]);
        }
        if (!$claim) {
            throw new \Exception(sprintf('Unable to locate claim %s in db', $brightstar->claimNumber));
        }
        
        $this->validateClaimDetails($claim, $brightstar);
    }

    public function validateClaimDetails(Claim $claim, Brightstar $brightstar)
    {
        $now = \DateTime::createFromFormat('U', time());
        $warningDate = $this->addBusinessDays($brightstar->orderDate, 3);

        $policy = $claim->getPolicy();
        $user = $policy->getUser();
        similar_text(mb_strtolower($user->getName()), $brightstar->name, $percent);
        if ($percent < 50 && !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_BRIGHTSTAR_NAME_MATCH)) {
            throw new \Exception(sprintf(
                'Brightstar Claim %s: %s does not match expected insuredName %s (match %0.1f)',
                $brightstar->claimNumber,
                $brightstar->name,
                $user->getName(),
                $percent
            ));
        } elseif ($percent < 75 && !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_BRIGHTSTAR_NAME_MATCH)) {
            $msg = sprintf(
                'Brightstar Claim %s: %s does not match expected insuredName %s (match %0.1f)',
                $brightstar->claimNumber,
                $brightstar->name,
                $user->getName(),
                $percent
            );
            if ($warningDate > $now) {
                $this->logger->warning($msg);
            }
            $this->errors[$brightstar->claimNumber][] = $msg;
        }

        if ($brightstar->postcode && $user->getBillingAddress() && !$this->postcodeCompare(
            $user->getBillingAddress()->getPostcode(),
            $brightstar->postcode
        ) && !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_BRIGHTSTAR_POSTCODE)) {
            $msg = sprintf(
                'Brightstar Claim %s: %s (%s) does not match expected postcode %s (%s)',
                $brightstar->claimNumber,
                $brightstar->postcode,
                $brightstar->getAddress()->__toString(),
                $user->getBillingAddress()->getPostcode(),
                $user->getBillingAddress()->__toString()
            );
            if ($warningDate > $now) {
                $this->logger->warning($msg);
            }
            $this->errors[$brightstar->claimNumber][] = $msg;
        }

        if (($brightstar->getServiceType() == Brightstar::SERVICE_TYPE_SWAP && !$claim->isPhoneReturnExpected()) ||
            ($brightstar->getServiceType() == Brightstar::SERVICE_TYPE_DELIVER && $claim->isPhoneReturnExpected())) {
            $msg = sprintf(
                'Brightstar Claim %s: Claim Type %s has incorrect Courier service %s',
                $brightstar->claimNumber,
                $claim->getType(),
                $brightstar->service
            );
            if ($warningDate > $now) {
                $this->logger->warning($msg);
            }
            $this->errors[$brightstar->claimNumber][] = $msg;
        }
        // although we would expect 1 day, if ordered after 4pm, then could take 2
        $expectedDeliveryDateBy = $this->addBusinessDays($brightstar->orderDate, 2);
        if ($expectedDeliveryDateBy < $brightstar->replacementReceivedDate) {
            $msg = sprintf(
                'Brightstar Claim %s: Replacement Received Date %s is not within 2 business day of order date %s',
                $brightstar->claimNumber,
                $brightstar->replacementReceivedDate->format('d M Y'),
                $brightstar->orderDate->format('d M Y')
            );
            if ($warningDate > $now) {
                $this->logger->warning($msg);
            }
            $this->errors[$brightstar->claimNumber][] = $msg;
        }
        // TODO: Validate replacement imei & delivery date (set?)
        // TODO: Validate returning imei matches original - will require saving off imei in list
        // TODO: Validate received back if swap
        // TODO: Warn if fmi active for a few days
        
        // TODO: Order date indicates approval date?
        // TODO: Validate handset against policy phone??
        // TODO: Validate Phone Number?
        // TODO: Validate Email?
        // TODO: Validate claim value?
        // TODO: Store handset value, fmi active, & repairable against claim?
    }

    public function emailReport()
    {
        $fileRepo = $this->dm->getRepository(BrightstarFile::class);
        $latestFiles = $fileRepo->findBy([], ['created' => 'desc'], 1);
        $latestFile = count($latestFiles) > 0 ? $latestFiles[0] : null;

        $successFiles = $fileRepo->findBy(['success' => true], ['created' => 'desc'], 1);
        $successFile = count($successFiles) > 0 ? $successFiles[0] : null;

        $this->mailer->sendTemplate(
            sprintf('Daily Brightstar'),
            'tech@so-sure.com',
            'AppBundle:Email:davies/brightstar.html.twig',
            [
                'latestFile' => $latestFile,
                'successFile' => $successFile,
                'errors' => $this->errors
            ]
        );

        return;
    }
}
