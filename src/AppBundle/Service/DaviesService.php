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

class DaviesService extends S3EmailService
{
    use CurrencyTrait;
    use DateTrait;

    /** @var ClaimsService */
    protected $claimsService;

    protected $mailer;

    public function setClaims($claimsService)
    {
        $this->claimsService = $claimsService;
    }

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    public function processExcelData($key, $data)
    {
        return $this->saveClaims($key, $data);
    }

    public function postProcess()
    {
        $this->claimsDailyEmail();
    }

    public function getNewS3File()
    {
        return new DaviesFile();
    }

    public function getColumnsFromSheetName($sheetName)
    {
        return DaviesClaim::getColumnsFromSheetName($sheetName);
    }

    public function createLineObject($line, $columns)
    {
        return DaviesClaim::create($line, $columns);
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
        } elseif ($daviesClaim->phoneReplacementCost < 0 && $claim->getStatus() == Claim::STATUS_INREVIEW) {
            // If phone replacement value is negative, an excess payment has been applied to the account
            // e.g. the user paid the excess, which indicates that the claim was approved
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
        if (strtolower($claim->getPolicy()->getPolicyNumber()) != strtolower($daviesClaim->policyNumber)) {
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
            // lower case & remove title
            $daviesInsuredName = strtolower($daviesClaim->insuredName);
            foreach (['Mr. ', 'Mr ', 'Mrs. ', 'Mrs ', 'Miss '] as $title) {
                $daviesInsuredName = str_replace($title, '', $daviesInsuredName);
            }
            similar_text(strtolower($claim->getPolicy()->getUser()->getName()), $daviesInsuredName, $percent);

            if ($percent < 50 && !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_DAVIES_NAME_MATCH)) {
                throw new \Exception(sprintf(
                    'Claim %s: %s does not match expected insuredName %s (match %0.1f)',
                    $daviesClaim->claimNumber,
                    $daviesClaim->insuredName,
                    $claim->getPolicy()->getUser()->getName(),
                    $percent
                ));
            } elseif ($percent < 75 && !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_DAVIES_NAME_MATCH)) {
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
            ) && !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_DAVIES_POSTCODE)) {
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
