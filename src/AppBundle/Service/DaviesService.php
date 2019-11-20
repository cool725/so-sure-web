<?php
namespace AppBundle\Service;

use AppBundle\Document\ImeiTrait;
use AppBundle\Document\Policy;
use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use AppBundle\Classes\DaviesHandlerClaim;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Feature;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\DaviesFile;
use Doctrine\ODM\MongoDB\DocumentManager;
use VasilDakov\Postcode\Postcode;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use AppBundle\Exception\ValidationException;
use AppBundle\Repository\ClaimRepository;

class DaviesService extends S3EmailService
{
    use CurrencyTrait;
    use DateTrait;
    use ImeiTrait;

    const MIN_LOSS_DESCRIPTION_LENGTH = 5;

    /** @var ClaimsService */
    protected $claimsService;

    /** @var FeatureService */
    protected $featureService;

    /** @var MailerService */
    protected $mailer;
    protected $validator;

    protected $fees = [];

    public function setClaims($claimsService)
    {
        $this->claimsService = $claimsService;
    }

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    public function setMailerMailer($mailer)
    {
        $this->mailer->setMailer($mailer);
    }

    public function setFeature($featureService)
    {
        $this->featureService = $featureService;
    }

    public function setValidator($validator)
    {
        $this->validator = $validator;
    }

    public function getFees()
    {
        return $this->fees;
    }

    public function clearFees()
    {
        $this->fees = [];
    }

    public function reportMissingClaims($daviesClaims)
    {
        $dbClaims = [];
        $processedClaims = [];
        $repoClaims = $this->dm->getRepository(Claim::class);
        $startOfToday = \DateTime::createFromFormat('U', time());
        $startOfToday = $this->startOfDay($startOfToday);
        $findAllClaims = $repoClaims->findBy([
            'recordedDate' => ['$lt' => $startOfToday],
            'handlingTeam' => Claim::TEAM_DAVIES
        ]);
        foreach ($findAllClaims as $claim) {
            $dbClaims[] = $claim->getNumber();
        }

        foreach ($daviesClaims as $daviesClaim) {
            $processedClaims[] = $daviesClaim->claimNumber;
        }

        $foundClaims = array_intersect($processedClaims, $dbClaims);

        $missingClaims = array_diff($dbClaims, $foundClaims);

        foreach ($missingClaims as $missingClaim) {
            if (isset($missingClaim)) {
                /** @var Claim $claim */
                $claim = $repoClaims->findOneBy(['number' => $missingClaim]);
                $msg = sprintf(
                    'Unable to locate db claim %s in the import file related to our policy %s',
                    $missingClaim,
                    $claim->getPolicy()->getPolicyNumber()
                );
                $this->errors[$missingClaim][] = $msg;
            }
        }
    }
    public function processExcelData($key, $data)
    {
        return $this->saveClaims($key, $data);
    }

    public function postProcess()
    {
        $this->claimsDailyEmail();
        $this->claimsDailyErrors();
    }

    public function getNewS3File()
    {
        return new DaviesFile();
    }

    public function getColumnsFromSheetName($sheetName)
    {
        return DaviesHandlerClaim::getColumnsFromSheetName($sheetName);
    }

    public function createLineObject($line, $columns)
    {
        return DaviesHandlerClaim::create($line, $columns);
    }

    public function saveClaims($key, array $daviesClaims)
    {
        // was using key for logging purposes - can be removed in the future
        \AppBundle\Classes\NoOp::ignore([$key]);
        $success = true;
        $claims = [];
        $openClaims = [];
        $multiple = [];

        $this->reportMissingClaims($daviesClaims);

        foreach ($daviesClaims as $daviesClaim) {
            // get the most recent claim that's open
            if ($daviesClaim->isOpen()) {
                if (!isset($openClaims[$daviesClaim->getPolicyNumber()]) ||
                    $daviesClaim->lossDate > $openClaims[$daviesClaim->getPolicyNumber()]) {
                    $openClaims[$daviesClaim->getPolicyNumber()] = $daviesClaim->lossDate;
                }
            }
            if (isset($claims[$daviesClaim->getPolicyNumber()]) &&
                $claims[$daviesClaim->getPolicyNumber()]) {
                if ($daviesClaim->isOpen()) {
                    $msg = sprintf(
                        'There are multiple open claims against policy %s. Please manually update the IMEI.',
                        $daviesClaim->getPolicyNumber()
                    );
                    $this->warnings[$daviesClaim->claimNumber][] = $msg;
                    $multiple[] = $daviesClaim->getPolicyNumber();
                }
            }
            $claims[$daviesClaim->getPolicyNumber()] = $daviesClaim->isOpen();
        }

        // Check for any claims that are closed that appear after an open claim
        foreach ($daviesClaims as $daviesClaim) {
            if (!$daviesClaim->isOpen() &&
                isset($openClaims[$daviesClaim->getPolicyNumber()]) &&
                $daviesClaim->lossDate > $openClaims[$daviesClaim->getPolicyNumber()]) {
                    // @codingStandardsIgnoreStart
                    $msg = sprintf(
                        'There is open claim against policy %s that is older then the closed claim of %s and needs to be closed. Unable to determine imei',
                        $daviesClaim->getPolicyNumber(),
                        $daviesClaim->claimNumber
                    );
                    // @codingStandardsIgnoreEnd
                    $this->errors[$daviesClaim->claimNumber][] = $msg;
                    $multiple[] = $daviesClaim->getPolicyNumber();
            }
        }

        // Check for any claims in db that are closed that appear after an open claim,
        // or any cases where there is more than 1 open claim
        foreach ($daviesClaims as $daviesClaim) {
            $repo = $this->dm->getRepository(Policy::class);
            /** @var Policy $policy */
            $policy = $repo->findOneBy(['policyNumber' => $daviesClaim->getPolicyNumber()]);
            if ($policy) {
                foreach ($policy->getClaims() as $claim) {
                    /** @var Claim $claim */
                    // 3 options - either db claim is closed or davies claim is closed, or both are open
                    $logError = false;
                    $preventImeiUpdate = false;
                    if (!$claim->isOpen() &&
                        isset($openClaims[$daviesClaim->getPolicyNumber()]) &&
                        $claim->getLossDate() > $openClaims[$daviesClaim->getPolicyNumber()] &&
                        $claim->getNumber() != $daviesClaim->claimNumber) {
                        $preventImeiUpdate = true;
                        if ($claim->getHandlingTeam() == Claim::TEAM_DAVIES) {
                            $logError = true;
                        }
                    } elseif (!$daviesClaim->isOpen() &&
                        $daviesClaim->lossDate > $claim->getLossDate() &&
                        $claim->getNumber() != $daviesClaim->claimNumber) {
                        $preventImeiUpdate = true;
                        if ($claim->getHandlingTeam() == Claim::TEAM_DAVIES) {
                            $logError = true;
                        }
                    } elseif ($claim->isOpen() && $daviesClaim->isOpen() &&
                        $claim->getNumber() != $daviesClaim->claimNumber) {
                        $preventImeiUpdate = true;
                    }

                    if ($preventImeiUpdate) {
                        $multiple[] = $policy->getPolicyNumber();
                    }

                    if ($logError) {
                        // @codingStandardsIgnoreStart
                        $msg = sprintf(
                            'There is open claim against policy %s that is older then the closed claim of %s and needs to be closed. Unable to determine imei',
                            $policy->getPolicyNumber(),
                            $claim->getNumber()
                        );
                        // @codingStandardsIgnoreEnd
                        $this->warnings[$claim->getNumber()][] = $msg;
                    }
                }
            }
        }

        foreach ($daviesClaims as $daviesClaim) {
            try {
                $skipImeiUpdate = in_array($daviesClaim->getPolicyNumber(), $multiple);
                $this->saveClaim($daviesClaim, $skipImeiUpdate);
            } catch (\Exception $e) {
                //$success = false;
                $this->errors[$daviesClaim->claimNumber][] = sprintf("%s [Record import failed]", $e->getMessage());
                // In case any of the db data failed validation, clear the changeset
                if ($claim = $this->getClaim($daviesClaim)) {
                    $this->dm->refresh($claim);
                }
            }
        }
        return $success;
    }

    private function getClaim($daviesClaim)
    {
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $claim = $repo->findOneBy(['number' => $daviesClaim->claimNumber]);
        // Davies swapped to a new claim numbering format
        // and appear to be unable to enter the correct data
        // sometimes they are leaving off the last 2 digits when entering the claim
        // on our system
        if (!$claim) {
            /** @var Claim $claim */
            $claim = $repo->findOneBy(['number' => mb_substr($daviesClaim->claimNumber, 0, -2)]);
            if ($claim) {
                if ($daviesClaim->getPolicyNumber() != $claim->getPolicy()->getPolicyNumber()) {
                    throw new \Exception(sprintf(
                        'Unable to locate claim %s in db with number matching. (%s != %s)',
                        $daviesClaim->claimNumber,
                        $daviesClaim->getPolicyNumber(),
                        $claim->getPolicy()->getPolicyNumber()
                    ));
                }
                $this->mailer->sendTemplate(
                    sprintf('Claim Number Update'),
                    'tech+ops@so-sure.com',
                    'AppBundle:Email:davies/incorrectClaimNumber.html.twig',
                    [
                        'claim' => $claim,
                        'claimNumber' => $daviesClaim->claimNumber,
                        'policyNumber' => $daviesClaim->getPolicyNumber(),
                    ]
                );
                $claim->setNumber($daviesClaim->claimNumber, true);
            }
        }

        return $claim;
    }

    /**
     * @param DaviesHandlerClaim $daviesClaim
     * @param boolean            $skipImeiUpdate
     * @return bool
     * @throws \Exception
     */
    public function saveClaim(DaviesHandlerClaim $daviesClaim, $skipImeiUpdate)
    {
        if ($daviesClaim->hasError()) {
            throw new \Exception(sprintf(
                'Claim %s has error status. Skipping, but claim should not be in the export most likely.',
                $daviesClaim->claimNumber
            ));
        }
        $claim = $this->getClaim($daviesClaim);
        if (!$claim) {
            throw new \Exception(sprintf('Unable to locate claim %s in db', $daviesClaim->claimNumber));
        } elseif ($claim->getHandlingTeam() != Claim::TEAM_DAVIES &&
            !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_HANDLING_TEAM)) {
            $msg = sprintf(
                'Claim %s is being processed by %s, not davies. Skipping davies import.',
                $daviesClaim->claimNumber,
                $claim->getHandlingTeam()
            );
            $this->sosureActions[$daviesClaim->claimNumber][] = $msg;

            return false;
        }

        $this->validateClaimDetails($claim, $daviesClaim);

        if ($claim->getType() != $daviesClaim->getClaimType()) {
            throw new \Exception(sprintf('Claims type does not match for claim %s', $daviesClaim->claimNumber));
        }
        if ($daviesClaim->getClaimStatus()) {
            $claim->setStatus($daviesClaim->getClaimStatus());
        } elseif ($daviesClaim->isApproved() && $claim->getStatus() == Claim::STATUS_INREVIEW) {
            $claim->setStatus(Claim::STATUS_APPROVED);
        }

        $claim->setDaviesStatus($daviesClaim->status);
        // TODO: May want to normalize the davies status as well, but then would want an additional column
        // $claim->setDaviesStatus($daviesClaim->getDaviesStatus());

        $claim->setExcess($daviesClaim->excess);
        $claim->setClaimHandlingFees($daviesClaim->handlingFees);
        $claim->setReservedValue($daviesClaim->reserved);

        // Davies data is incorrect and incurred / totalIncurred are swapped
        $claim->setIncurred($daviesClaim->totalIncurred);
        $claim->setTotalIncurred($daviesClaim->incurred);

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
            $yesterday = \DateTime::createFromFormat('U', time());
            if ($daviesClaim->replacementReceivedDate) {
                $yesterday = clone $daviesClaim->replacementReceivedDate;
            }
            $yesterday = $this->subBusinessDays($yesterday, 1);

            $claim->setApprovedDate($yesterday);
        }

        $claim->setReplacementImei($daviesClaim->replacementImei);
        $claim->setReplacementReceivedDate($daviesClaim->replacementReceivedDate);
        $claim->setReplacementPhoneDetails($daviesClaim->getReplacementPhoneDetails());

        $validator = new AlphanumericSpaceDotValidator();
        $claim->setDescription($validator->conform($daviesClaim->lossDescription));
        $claim->setLocation($daviesClaim->location);

        $claim->setClosedDate($daviesClaim->dateClosed);
        $claim->setCreatedDate($daviesClaim->dateCreated);
        $claim->setNotificationDate($daviesClaim->notificationDate);
        $claim->setLossDate($daviesClaim->lossDate);

        $claim->setShippingAddress($daviesClaim->shippingAddress);

        $claim->setInitialSuspicion($daviesClaim->initialSuspicion);
        $claim->setFinalSuspicion($daviesClaim->finalSuspicion);

        $this->updatePolicy($claim, $daviesClaim, $skipImeiUpdate);

        $errors = $this->validator->validate($claim);
        if (count($errors) > 0) {
            //\Doctrine\Common\Util\Debug::dump($errors, 3);
            $this->logger->error(sprintf(
                'Claim %s/%s (status: %s) failed validation. Discarding updates. Error: %s',
                $claim->getId(),
                $daviesClaim->claimNumber,
                $claim->getStatus(),
                json_encode($errors)
            ));
            $this->dm->clear();
        }

        $this->dm->flush();

        $this->postValidateClaimDetails($claim, $daviesClaim);

        $this->claimsService->processClaim($claim);

        // Only for active/unpaid policies with a theft/lost claim that have been repudiated
        if ($daviesClaim->miStatus === DaviesHandlerClaim::MISTATUS_REPUDIATED &&
            in_array($claim->getType(), [Claim::TYPE_LOSS, Claim::TYPE_THEFT]) &&
            in_array($claim->getPolicy()->getStatus(), [
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID
            ])
        ) {
            $body = sprintf(
                'Verify that policy %s / %s has a rejected claim and if so, policy should be cancelled',
                $claim->getPolicy()->getPolicyNumber(),
                $claim->getPolicy()->getId()
            );
            $this->mailer->send(
                'Please cancel Policy',
                'support@wearesosure.com',
                $body
            );
        }

        return count($errors) == 0;
    }

    /**
     * @param Claim              $claim
     * @param DaviesHandlerClaim $daviesClaim
     * @throws \Exception
     */
    public function validateClaimDetails(Claim $claim, DaviesHandlerClaim $daviesClaim)
    {
        if (mb_strtolower($claim->getPolicy()->getPolicyNumber()) != mb_strtolower($daviesClaim->getPolicyNumber())) {
            throw new \Exception(sprintf(
                'Claim %s does not match policy number %s',
                $daviesClaim->claimNumber,
                $daviesClaim->getPolicyNumber()
            ));
        }

        if ($daviesClaim->replacementImei && !$this->isImei($daviesClaim->replacementImei)) {
            throw new \Exception(sprintf('Invalid replacement imei %s', $daviesClaim->replacementImei));
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

        if ($daviesClaim->replacementReceivedDate && $daviesClaim->replacementReceivedDate < $daviesClaim->lossDate) {
            throw new \Exception(sprintf(
                'Claim %s has a replacement received date prior to loss date',
                $daviesClaim->claimNumber
            ));
        }

        if ($daviesClaim->replacementReceivedDate &&
            (!$daviesClaim->replacementMake || !$daviesClaim->replacementModel)) {
            throw new \Exception(sprintf(
                'Claim %s has a replacement received date without a replacement make/model',
                $daviesClaim->claimNumber
            ));
        }

        $now = \DateTime::createFromFormat('U', time());
        if ($daviesClaim->isOpen() || ($daviesClaim->dateClosed && $daviesClaim->dateClosed->diff($now)->days < 5)) {
            // lower case & remove title
            $daviesInsuredName = mb_strtolower($daviesClaim->insuredName);
            foreach (['Mr. ', 'Mr ', 'Mrs. ', 'Mrs ', 'Miss '] as $title) {
                $daviesInsuredName = str_replace($title, '', $daviesInsuredName);
            }
            similar_text(mb_strtolower($claim->getPolicy()->getUser()->getName()), $daviesInsuredName, $percent);

            if ($percent < 50 && !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_NAME_MATCH)) {
                throw new \Exception(sprintf(
                    'Claim %s: %s does not match expected insuredName %s (match %0.1f)',
                    $daviesClaim->claimNumber,
                    $daviesClaim->insuredName,
                    $claim->getPolicy()->getUser()->getName(),
                    $percent
                ));
            } elseif ($percent < 75 && !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_NAME_MATCH)) {
                $msg = sprintf(
                    'Claim %s: %s does not match expected insuredName %s (match %0.1f)',
                    $daviesClaim->claimNumber,
                    $daviesClaim->insuredName,
                    $claim->getPolicy()->getUser()->getName(),
                    $percent
                );
                $this->warnings[$daviesClaim->claimNumber][] = $msg;
            }

            if ($daviesClaim->riskPostCode && $claim->getPolicy()->getUser()->getBillingAddress() &&
                !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_POSTCODE)) {
                if (!$this->postcodeCompare(
                    $claim->getPolicy()->getUser()->getBillingAddress()->getPostcode(),
                    $daviesClaim->riskPostCode
                )) {
                    $msg = sprintf(
                        'Claim %s: %s does not match expected postcode %s',
                        $daviesClaim->claimNumber,
                        $daviesClaim->riskPostCode,
                        $claim->getPolicy()->getUser()->getBillingAddress()->getPostcode()
                    );
                    $this->warnings[$daviesClaim->claimNumber][] = $msg;
                }
            }
        }

        /** @var PhonePolicy $phonePolicy */
        $phonePolicy = $claim->getPolicy();
        if (!$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_REPLACEMENT_COST_HIGHER) &&
            $daviesClaim->phoneReplacementCost > $phonePolicy->getPhone()->getInitialPrice()) {
            $msg = sprintf(
                'Device replacement cost for claim %s is greater than initial price of the device',
                $daviesClaim->claimNumber
            );
            $this->warnings[$daviesClaim->claimNumber][] = $msg;
        }
        // Open Non-Warranty Claims are expected to either have a total incurred value or a reserved value
        if ($daviesClaim->isOpen() && !$daviesClaim->isClaimWarranty() &&
            $this->areEqualToTwoDp($daviesClaim->getIncurred(), 0) &&
            $this->areEqualToTwoDp($daviesClaim->getReserved(), 0)) {
            $msg = sprintf('Claim %s does not have a reserved value', $daviesClaim->claimNumber);
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }

        // assume validated prices for pre-picsure policies
        $validated = true;
        if ($phonePolicy->isPicSurePolicy()) {
            $validated = $phonePolicy->isPicSureValidated();
            // After the initial import, once the pic-sure status changes to CLIAM-APPROVED
            // we need to check to see if the claim is the same to avoid errors
            if ($validated && $phonePolicy->getPicSureClaimApprovedClaim() && $claim->getId()) {
                $validated = $phonePolicy->getPicSureClaimApprovedClaim()->getId() != $claim->getId();
            }
        }

        // negative excess indicates that the excess was paid by the policy holder
        // davies business process is to update this value once the invoice is recevied by the supplier
        // which should be at the same time the replacement imei is provided
        $negativeExcessAllowed = mb_strlen($daviesClaim->replacementImei) == 0;
        $isExcessValueCorrect = $daviesClaim->isExcessValueCorrect($claim, $negativeExcessAllowed);

        // many of davies excess figures are incorrect if withdrawn and no actual need to validate in those cases
        if (!$isExcessValueCorrect &&
            !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_INCORRECT_EXCESS) &&
            !in_array($daviesClaim->getClaimStatus(), [
                Claim::STATUS_DECLINED,
                Claim::STATUS_WITHDRAWN
            ])
        ) {
            $msg = sprintf(
                'Claim %s does not have the correct excess value. Expected %0.2f Actual %0.2f for %s/%s',
                $daviesClaim->claimNumber,
                $daviesClaim->getExpectedExcessValue($claim),
                $daviesClaim->excess,
                $daviesClaim->getClaimType(),
                $daviesClaim->getClaimStatus()
            );
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }

        if ($daviesClaim->isIncurredValueCorrect() === false) {
            $msg = sprintf(
                'Claim %s does not have the correct incurred value. Expected %0.2f Actual %0.2f',
                $daviesClaim->claimNumber,
                $daviesClaim->getExpectedIncurred(),
                $daviesClaim->incurred
            );
            // seems to be an issue with small difference in the incurred value related to receipero fees
            // if under £2, then assume that to be the case and move to the fees section
            if (abs($daviesClaim->getExpectedIncurred() - $daviesClaim->incurred) < 2) {
                $this->fees[$daviesClaim->claimNumber][] = $msg;
            } else {
                $this->errors[$daviesClaim->claimNumber][] = $msg;
            }
        }

        $approvedDate = null;
        if ($claim->getApprovedDate()) {
            $approvedDate = $this->startOfDay(clone $claim->getApprovedDate());
        } elseif ($daviesClaim->replacementReceivedDate) {
            $approvedDate = $this->startOfDay(clone $daviesClaim->replacementReceivedDate);
        }
        if ($approvedDate) {
            $fiveBusinessDays = $this->addBusinessDays($approvedDate, 5);
            if ($daviesClaim->isPhoneReplacementCostCorrect() === false &&
                $fiveBusinessDays < \DateTime::createFromFormat('U', time())) {
                $msg = sprintf(
                    'Claim %s does not have the correct phone replacement cost. Expected > 0 Actual %0.2f',
                    $daviesClaim->claimNumber,
                    $daviesClaim->phoneReplacementCost
                );
                $this->errors[$daviesClaim->claimNumber][] = $msg;
            }
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
            $this->fees[$daviesClaim->claimNumber][] = $msg;
        }

        if ($daviesClaim->isClosed(true) && $daviesClaim->reserved > 0) {
            $msg = sprintf(
                'Claim %s is closed, yet still has a reserve fee.',
                $daviesClaim->claimNumber
            );
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }

        if (!$claim->getReplacementReceivedDate() && $daviesClaim->replacementReceivedDate) {
            // We should be notified the next day when a replacement device is delivered
            // so we can follow up with our customer. Unlikely to occur.
            $ago = \DateTime::createFromFormat('U', time());
            $ago = $this->subBusinessDays($ago, 1);

            if ($daviesClaim->replacementReceivedDate < $ago) {
                $msg = sprintf(
                    'Claim %s has a delayed replacement date (%s) which is more than 1 business day ago (%s)',
                    $daviesClaim->claimNumber,
                    $daviesClaim->replacementReceivedDate->format(\DateTime::ATOM),
                    $ago->format(\DateTime::ATOM)
                );
                $this->warnings[$daviesClaim->claimNumber][] = $msg;
            }
        }

        $twoWeekAgo = \DateTime::createFromFormat('U', time());
        $twoWeekAgo = $twoWeekAgo->sub(new \DateInterval('P2W'));
        if ($claim->getApprovedDate() && in_array($daviesClaim->getClaimStatus(), [
            Claim::STATUS_DECLINED,
            Claim::STATUS_WITHDRAWN
        ])) {
            $msg = sprintf(
                'Claim %s was previously approved, however is now withdrawn/declined. SO-SURE to remove approved date',
                $daviesClaim->claimNumber
            );
            $this->sosureActions[$daviesClaim->claimNumber][] = $msg;
        } elseif ($claim->getApprovedDate() && !$daviesClaim->isApproved()) {
            $msg = sprintf(
                'Claim %s was previously approved, however no longer appears to be. SO-SURE to remove approved date',
                $daviesClaim->claimNumber
            );
            $this->sosureActions[$daviesClaim->claimNumber][] = $msg;
        } elseif ($claim->getApprovedDate() && $claim->getApprovedDate() <= $twoWeekAgo) {
            $items = [];
            if (!$daviesClaim->replacementReceivedDate) {
                $items[] = 'received date';
            }
            if (!$daviesClaim->replacementImei && !$daviesClaim->isReplacementRepaired() &&
                !in_array('replacementImei', $daviesClaim->unobtainableFields)) {
                $items[] = 'imei';
            }
            if (!$daviesClaim->replacementMake || !$daviesClaim->replacementModel) {
                $items[] = 'phone';
            }
            if (count($items) > 0) {
                $msg = sprintf(
                    'Claim %s was approved over 2 weeks ago (%s), however, the replacement data not recorded (%s).',
                    $daviesClaim->claimNumber,
                    $claim->getApprovedDate()->format(\DateTime::ATOM),
                    implode('; ', $items)
                );
                $this->errors[$daviesClaim->claimNumber][] = $msg;
            }
        } elseif ($claim->isClosed() && $claim->getStatus() != $daviesClaim->getClaimStatus()) {
            $msg = sprintf(
                'Claim %s was previously closed (%s), however status is now %s (%s). SO-SURE to investigate',
                $daviesClaim->claimNumber,
                $claim->getStatus(),
                $daviesClaim->getClaimStatus() ?: 'open',
                $daviesClaim->miStatus
            );
            $this->sosureActions[$daviesClaim->claimNumber][] = $msg;
        }

        if (!$daviesClaim->replacementImei && !$daviesClaim->isReplacementRepaired() &&
            in_array('replacementImei', $daviesClaim->unobtainableFields)) {
            $msg = sprintf(
                'Claim %s does not have a replacement IMEI - unobtainable. Contact customer if possible.',
                $daviesClaim->claimNumber
            );
            $this->warnings[$daviesClaim->claimNumber][] = $msg;
        }

        if (!$daviesClaim->replacementImei && !$daviesClaim->isReplacementRepaired() &&
            $daviesClaim->getClaimStatus() == Claim::STATUS_SETTLED) {
            $msg = sprintf(
                'Claim %s is settled without a replacement imei.',
                $daviesClaim->claimNumber
            );
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }

        if ($daviesClaim->isOpen() && $claim->getPhonePolicy() && $daviesClaim->replacementImei &&
            $daviesClaim->replacementImei == $claim->getPhonePolicy()->getImei() && (
            !$daviesClaim->replacementMake || !$daviesClaim->replacementModel)) {
            // @codingStandardsIgnoreStart
            $msg = sprintf(
                'Claim %s has a replacement imei that matches the policy but is missing a replacement make and/or model. This is likely to be a data entry mistake.',
                $daviesClaim->claimNumber
            );
            // @codingStandardsIgnoreEnd
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }

        $threeMonthsAgo = \DateTime::createFromFormat('U', time());
        $threeMonthsAgo = $threeMonthsAgo->sub(new \DateInterval('P3M'));
        if ($daviesClaim->isOpen() && $daviesClaim->replacementReceivedDate &&
            $daviesClaim->replacementReceivedDate < $threeMonthsAgo) {
            $msg = sprintf(
                'Claim %s should be closed. Replacement was delivered more than 3 months ago on %s.',
                $daviesClaim->claimNumber,
                $daviesClaim->replacementReceivedDate->format(\DateTime::ATOM)
            );
            $this->errors[$daviesClaim->claimNumber][] = $msg;
        }

        if (!isset($daviesClaim->initialSuspicion)) {
            $msg = sprintf(
                'Claim %s does not have initialSuspicion flag set.',
                $daviesClaim->claimNumber
            );
            $this->warnings[$daviesClaim->claimNumber][] = $msg;
        }
        if (in_array($claim->getStatus(), array(Claim::STATUS_SETTLED, Claim::STATUS_APPROVED))
            && !isset($daviesClaim->finalSuspicion)) {
            $msg = sprintf(
                'Claim %s should have finalSuspicion flag set.',
                $daviesClaim->claimNumber
            );
            $this->warnings[$daviesClaim->claimNumber][] = $msg;
        }

        if (mb_strlen($daviesClaim->lossDescription) < self::MIN_LOSS_DESCRIPTION_LENGTH) {
            $msg = sprintf(
                'Claim %s does not have a detailed loss description',
                $daviesClaim->claimNumber
            );
            $this->warnings[$daviesClaim->claimNumber][] = $msg;
        }
    }

    public function postValidateClaimDetails(Claim $claim, DaviesHandlerClaim $daviesClaim)
    {
        if ($claim->getApprovedDate() && $claim->getReplacementReceivedDate() &&
            $claim->getApprovedDate() > $claim->getReplacementReceivedDate()) {
            $msg = sprintf(
                'Claim %s has an approved date (%s) more recent than the received date (%s)',
                $daviesClaim->claimNumber,
                $claim->getApprovedDate()->format(\DateTime::ATOM),
                $claim->getReplacementReceivedDate()->format(\DateTime::ATOM)
            );
            $this->warnings[$daviesClaim->claimNumber][] = $msg;
        }

        // Should be in post validate in case the record fails import
        if (!$claim->getReplacementPhone() && $claim->getReplacementPhoneDetails() &&
            $claim->getStatus() == Claim::STATUS_SETTLED) {
            $msg = sprintf(
                'Claim %s is settled without a replacement phone being set. SO-SURE to set replacement phone.',
                $daviesClaim->claimNumber
            );
            $this->sosureActions[$daviesClaim->claimNumber][] = $msg;
        }
    }

    public function getReplacementPhone(DaviesHandlerClaim $daviesClaim)
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

    /**
     * @param Claim              $claim
     * @param DaviesHandlerClaim $daviesClaim
     * @param boolean            $skipImeiUpdate
     * @throws \Exception
     */
    public function updatePolicy(Claim $claim, DaviesHandlerClaim $daviesClaim, $skipImeiUpdate)
    {
        /** @var PhonePolicy $policy */
        $policy = $claim->getPolicy();
        // Closed claims should not replace the imei as if there are multiple claims
        // for a policy it will trigger a salva policy update
        if ($claim->isOpen()) {
            // We've replaced their phone with a new imei number
            if ($claim->getReplacementImei() &&
                $claim->getReplacementImei() != $policy->getImei() && !$skipImeiUpdate) {
                // Imei has changed, but we can't change their policy premium, which is fixed
                // If there are multiple open claims, don't update the imei!
                $policy->adjustImei($claim->getReplacementImei());

                // If phone has been updated (unlikely at the moment)
                if ($claim->getReplacementPhone()) {
                    $policy->setPhone($claim->getReplacementPhone());
                }
                $this->mailer->sendTemplate(
                    sprintf('Verify Policy %s IMEI Update', $policy->getPolicyNumber()),
                    ['tech+ops@so-sure.com', 'marketing@so-sure.com'],
                    'AppBundle:Email:davies/checkPhone.html.twig',
                    ['policy' => $policy, 'daviesClaim' => $daviesClaim, 'skipImeiUpdate' => $skipImeiUpdate]
                );
            }
        } elseif (count($policy->getClaims()) == 1) {
            // Davies may be closing the claim before reporting the imei properly (not following process)
            // , so if there's only 1 claim on the policy without an imei number, report it properly
            if ($claim->getReplacementImei() &&
                $claim->getReplacementImei() != $policy->getImei()) {
                $this->mailer->sendTemplate(
                    sprintf('Verify Policy %s IMEI Update', $policy->getPolicyNumber()),
                    ['tech+ops@so-sure.com', 'marketing@so-sure.com'],
                    'AppBundle:Email:davies/checkPhone.html.twig',
                    ['policy' => $policy, 'daviesClaim' => $daviesClaim, 'skipImeiUpdate' => true]
                );
            }
        }

        if ($claim->getReplacementImei() && !$claim->getReplacementReceivedDate()) {
            if (!$policy->getImeiReplacementDate()) {
                throw new \Exception(sprintf(
                    'Expected imei replacement date for policy %s',
                    $policy->getPolicyNumber()
                ));
            }

            $now = \DateTime::createFromFormat('U', time());
            // no set time of day when the report is sent, so for this, just assume the day, not time
            $replacementDay = $this->startOfDay(clone $policy->getImeiReplacementDate());
            $twoBusinessDays = $this->addBusinessDays($replacementDay, 2);
            if ($now >= $twoBusinessDays) {
                $msg = sprintf(
                    'Claim %s is missing a replacement recevied date (expected 2 days after imei replacement)',
                    $daviesClaim->claimNumber
                );
                $this->errors[$daviesClaim->claimNumber][] = $msg;
            }
        }
    }

    public function claimsDailyEmail()
    {
        $phoneRepo = $this->dm->getRepository(Phone::class);
        $highDemandPhones = $phoneRepo->findBy(['newHighDemand' => true]);

        $fileRepo = $this->dm->getRepository(DaviesFile::class);
        $latestFiles = $fileRepo->findBy([], ['created' => 'desc'], 1);
        $latestFile = count($latestFiles) > 0 ? $latestFiles[0] : null;

        $successFiles = $fileRepo->findBy(['success' => true], ['created' => 'desc'], 1);
        $successFile = count($successFiles) > 0 ? $successFiles[0] : null;

        /** @var ClaimRepository $claimsRepo */
        $claimsRepo = $this->dm->getRepository(Claim::class);
        $claims = $claimsRepo->findOutstanding(Claim::TEAM_DAVIES);

        $this->mailer->sendTemplate(
            sprintf('Davies Daily Claims Report'),
            'tech+ops@so-sure.com',
            'AppBundle:Email:claimsHandler/dailyEmail.html.twig',
            [
                'claims' => $claims,
                'latestFile' => $latestFile,
                'successFile' => $successFile,
                'warnings' => $this->warnings,
                'errors' => $this->errors,
                'sosureActions' => $this->sosureActions,
                'fees' => $this->fees,
                'title' => 'Davies Daily Claims Report',
                'highDemandPhones' => $highDemandPhones,
                'claims_number_route' => 'admin_claim_number',
                'claims_policy_route' => 'admin_policy',
                'claims_route' => 'admin_claims',
            ]
        );

        return count($claims);
    }

    public function claimsDailyErrors()
    {
        $fileRepo = $this->dm->getRepository(DaviesFile::class);
        $latestFiles = $fileRepo->findBy([], ['created' => 'desc'], 1);
        $latestFile = count($latestFiles) > 0 ? $latestFiles[0] : null;

        $successFiles = $fileRepo->findBy(['success' => true], ['created' => 'desc'], 1);
        $successFile = count($successFiles) > 0 ? $successFiles[0] : null;

        if (count($this->errors) > 0) {
            $emails = 'tech+ops@so-sure.com';
            if ($this->featureService->isEnabled(Feature::FEATURE_DAVIES_IMPORT_ERROR_EMAIL)) {
                $emails = DaviesHandlerClaim::$errorEmailAddresses;
            }

            $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), 'davies-errors.csv');
            $lines = [];
            foreach ($this->errors as $clamId => $errors) {
                foreach ($errors as $error) {
                    $lines[] = sprintf('"%s", "%s"', $clamId, str_replace('"', "''", $error));
                }
            }
            $data = implode(PHP_EOL, $lines);
            file_put_contents($tmpFile, $data);

            $this->mailer->sendTemplate(
                sprintf('Errors in So-Sure Mobile - Daily Claims Report'),
                $emails,
                'AppBundle:Email:claimsHandler/dailyEmail.html.twig',
                [
                    'latestFile' => $latestFile,
                    'successFile' => $successFile,
                    'errors' => $this->errors,
                    'warnings' => null,
                    'sosureActions' => null,
                    'claims' => null,
                    'fees' => $this->fees,
                    'title' => 'Errors in So-Sure Mobile - Daily Claims Report',
                    'claims_number_route' => 'claims_claim_number',
                    'claims_policy_route' => 'claims_policy',
                    'claims_route' => 'claims_claims',
                ],
                null,
                null,
                [$tmpFile]
            );
        }

        return count($this->errors);
    }
}
