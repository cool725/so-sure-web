<?php
namespace AppBundle\Service;

use AppBundle\Classes\DirectGroupHandlerClaim;
use AppBundle\Document\File\DirectGroupFile;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\Policy;
use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Feature;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\DocumentManager;
use VasilDakov\Postcode\Postcode;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use AppBundle\Exception\ValidationException;
use AppBundle\Repository\ClaimRepository;

class DirectGroupService extends ExcelSftpService
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

    public function reportMissingClaims($directGroupClaims)
    {
        $dbClaims = [];
        $processedClaims = [];
        $repoClaims = $this->dm->getRepository(Claim::class);
        $startOfToday = \DateTime::createFromFormat('U', time());
        $startOfToday = $this->startOfDay($startOfToday);
        $findAllClaims = $repoClaims->findBy([
            'recordedDate' => ['$lt' => $startOfToday],
            'handlingTeam' => Claim::TEAM_DIRECT_GROUP,
        ]);
        foreach ($findAllClaims as $claim) {
            $dbClaims[] = $claim->getNumber();
        }

        foreach ($directGroupClaims as $directGroupClaim) {
            $processedClaims[] = $directGroupClaim->claimNumber;
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

    public function claimsDailyEmail()
    {
        $phoneRepo = $this->dm->getRepository(Phone::class);
        $highDemandPhones = $phoneRepo->findBy(['newHighDemand' => true]);

        $fileRepo = $this->dm->getRepository(DirectGroupFile::class);
        $latestFiles = $fileRepo->findBy([], ['created' => 'desc'], 1);
        $latestFile = count($latestFiles) > 0 ? $latestFiles[0] : null;

        $successFiles = $fileRepo->findBy(['success' => true], ['created' => 'desc'], 1);
        $successFile = count($successFiles) > 0 ? $successFiles[0] : null;

        /** @var ClaimRepository $claimsRepo */
        $claimsRepo = $this->dm->getRepository(Claim::class);
        $claims = $claimsRepo->findOutstanding(Claim::TEAM_DIRECT_GROUP);

        $this->mailer->sendTemplate(
            sprintf('Direct Group Daily Claims Report'),
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
                'title' => 'Direct Group Daily Claims Report',
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
        $fileRepo = $this->dm->getRepository(DirectGroupFile::class);
        $latestFiles = $fileRepo->findBy([], ['created' => 'desc'], 1);
        $latestFile = count($latestFiles) > 0 ? $latestFiles[0] : null;

        $successFiles = $fileRepo->findBy(['success' => true], ['created' => 'desc'], 1);
        $successFile = count($successFiles) > 0 ? $successFiles[0] : null;

        if (count($this->errors) > 0) {
            $emails = DirectGroupHandlerClaim::$errorEmailAddresses;

            $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), 'dg-errors.csv');
            $lines = [];
            foreach ($this->errors as $clamId => $errors) {
                foreach ($errors as $error) {
                    $lines[] = sprintf('"%s", "%s"', $clamId, str_replace('"', "''", $error));
                }
            }
            $data = implode(PHP_EOL, $lines);
            file_put_contents($tmpFile, $data);

            $this->mailer->sendTemplate(
                sprintf('Errors in Daily Claims Report (Direct Group)'),
                $emails,
                'AppBundle:Email:claimsHandler/dailyEmail.html.twig',
                [
                    'latestFile' => $latestFile,
                    'successFile' => $successFile,
                    'errors' => $this->errors,
                    'warnings' => $this->warnings,
                    'sosureActions' => null,
                    'claims' => null,
                    'fees' => $this->fees,
                    'title' => 'Errors in Daily Claims Report',
                    'claims_number_route' => 'admin_claim_number',
                    'claims_policy_route' => 'admin_policy',
                    'claims_route' => 'admin_claims',
                ],
                null,
                null,
                [$tmpFile]
            );

            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        return count($this->errors);
    }

    public function getNewS3File()
    {
        return new DirectGroupFile();
    }

    public function getColumnsFromSheetName($sheetName)
    {
        return DirectGroupHandlerClaim::getColumnsFromSheetName($sheetName);
    }

    public function createLineObject($line, $columns)
    {
        return DirectGroupHandlerClaim::create($line, $columns);
    }

    public function saveClaims($key, array $directGroupClaims)
    {
        // was using key for logging purposes - can be removed in the future
        \AppBundle\Classes\NoOp::ignore([$key]);
        $success = true;
        $claims = [];
        $openClaims = [];
        $openClaimsNumber = [];
        $multiple = [];

        $this->reportMissingClaims($directGroupClaims);

        foreach ($directGroupClaims as $directGroupClaim) {
            // get the most recent claim that's open
            if ($directGroupClaim->isOpen()) {
                if (!isset($openClaims[$directGroupClaim->getPolicyNumber()]) ||
                    $directGroupClaim->lossDate > $openClaims[$directGroupClaim->getPolicyNumber()]) {
                    $openClaims[$directGroupClaim->getPolicyNumber()] = $directGroupClaim->lossDate;
                    $openClaimsNumber[$directGroupClaim->getPolicyNumber()] = $directGroupClaim->claimNumber;
                }
            }
            if (isset($claims[$directGroupClaim->getPolicyNumber()]) &&
                $claims[$directGroupClaim->getPolicyNumber()]) {
                if ($directGroupClaim->isOpen()) {
                    $msg = sprintf(
                        'There are multiple open claims against policy %s. Please manually update the IMEI.',
                        $directGroupClaim->getPolicyNumber()
                    );
                    $this->warnings[$directGroupClaim->claimNumber][] = $msg;
                    $multiple[] = $directGroupClaim->getPolicyNumber();
                }
            }
            $claims[$directGroupClaim->getPolicyNumber()] = $directGroupClaim->isOpen();
        }

        // Check for any claims that are closed that appear after an open claim
        foreach ($directGroupClaims as $directGroupClaim) {
            if (!$directGroupClaim->isOpen() &&
                isset($openClaims[$directGroupClaim->getPolicyNumber()]) &&
                $directGroupClaim->lossDate > $openClaims[$directGroupClaim->getPolicyNumber()]) {
                    // @codingStandardsIgnoreStart
                    $msg = sprintf(
                        'There is open claim %s against policy %s that is older (%s) then the closed claim (%s) of %s and needs to be closed. Unable to determine imei. [%s]',
                        $openClaimsNumber[$directGroupClaim->getPolicyNumber()],
                        $directGroupClaim->getPolicyNumber(),
                        $openClaims[$directGroupClaim->getPolicyNumber()] ?
                            $openClaims[$directGroupClaim->getPolicyNumber()]->format('Y-m-d') :
                            '?',
                        $directGroupClaim->lossDate ? $directGroupClaim->lossDate->format('Y-m-d') : '?',
                        $directGroupClaim->claimNumber,
                        'R1'
                    );
                    // @codingStandardsIgnoreEnd
                    $this->errors[$directGroupClaim->claimNumber][] = $msg;
                    $multiple[] = $directGroupClaim->getPolicyNumber();
            }
        }

        // Check for any claims in db that are closed that appear after an open claim
        foreach ($directGroupClaims as $directGroupClaim) {
            $repo = $this->dm->getRepository(Policy::class);
            /** @var Policy $policy */
            $policy = $repo->findOneBy(['policyNumber' => $directGroupClaim->getPolicyNumber()]);
            if ($policy) {
                foreach ($policy->getClaims() as $claim) {
                    /** @var Claim $claim */
                    // 3 options - either db claim is closed or dg claim is closed, or both are open
                    $logError = false;
                    $preventImeiUpdate = false;
                    if (!$claim->isOpen() &&
                        isset($openClaims[$directGroupClaim->getPolicyNumber()]) &&
                        $claim->getLossDate() > $openClaims[$directGroupClaim->getPolicyNumber()] &&
                        $claim->getNumber() != $directGroupClaim->claimNumber) {
                        $preventImeiUpdate = true;
                        if ($claim->getHandlingTeam() == Claim::TEAM_DIRECT_GROUP) {
                            // @codingStandardsIgnoreStart
                            $msg = sprintf(
                                'There is open claim %s against policy %s that is older (%s) then the closed claim (%s) of %s and needs to be closed. Unable to determine imei. [%s]',
                                $openClaimsNumber[$directGroupClaim->getPolicyNumber()],
                                $claim->getPolicyNumber(),
                                $openClaims[$directGroupClaim->getPolicyNumber()] ?
                                    $openClaims[$directGroupClaim->getPolicyNumber()]->format('Y-m-d') :
                                    '?',
                                $claim->getLossDate() ? $claim->getLossDate()->format('Y-m-d') : '?',
                                $claim->getNumber(),
                                'R2'
                            );
                            // @codingStandardsIgnoreEnd
                            $this->errors[$claim->getNumber()][] = $msg;
                        }
                    } elseif (!$directGroupClaim->isOpen() &&
                        $directGroupClaim->lossDate > $claim->getLossDate() &&
                        $claim->isOpen() &&
                        $claim->getNumber() != $directGroupClaim->claimNumber) {
                        $preventImeiUpdate = true;
                        if ($claim->getHandlingTeam() == Claim::TEAM_DIRECT_GROUP) {
                            // @codingStandardsIgnoreStart
                            $msg = sprintf(
                                'There is open claim %s against policy %s that is older (%s) then the closed claim (%s) of %s and needs to be closed. Unable to determine imei. [%s]',
                                $claim->getNumber(),
                                $directGroupClaim->getPolicyNumber(),
                                $claim->getLossDate() ? $claim->getLossDate()->format('Y-m-d') : '?',
                                $directGroupClaim->lossDate ? $directGroupClaim->lossDate->format('Y-m-d') : '?',
                                $directGroupClaim->claimNumber,
                                'R3'
                            );
                            // @codingStandardsIgnoreEnd
                            $this->errors[$claim->getNumber()][] = $msg;
                        }
                    } elseif ($claim->isOpen() && $directGroupClaim->isOpen() &&
                        $claim->getNumber() != $directGroupClaim->claimNumber) {
                        $preventImeiUpdate = true;
                    }

                    if ($preventImeiUpdate) {
                        $multiple[] = $policy->getPolicyNumber();
                    }
                }
            }
        }

        foreach ($directGroupClaims as $directGroupClaim) {
            try {
                $skipImeiUpdate = in_array($directGroupClaim->getPolicyNumber(), $multiple);
                $this->saveClaim($directGroupClaim, $skipImeiUpdate);
            } catch (\Exception $e) {
                //$success = false;
                $this->errors[$directGroupClaim->claimNumber][] = sprintf(
                    "%s [Record import failed]",
                    $e->getMessage()
                );
                // In case any of the db data failed validation, clear the changeset
                if ($claim = $this->getClaim($directGroupClaim)) {
                    $this->dm->refresh($claim);
                }
            }
        }
        return $success;
    }

    private function getClaim($directGroupClaim)
    {
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $claim = $repo->findOneBy(['number' => $directGroupClaim->claimNumber]);
        // Davies swapped to a new claim numbering format
        // and appear to be unable to enter the correct data
        // sometimes they are leaving off the last 2 digits when entering the claim
        // on our system
        if (!$claim) {
            /** @var Claim $claim */
            $claim = $repo->findOneBy(['number' => mb_substr($directGroupClaim->claimNumber, 0, -2)]);
            if ($claim) {
                if ($directGroupClaim->getPolicyNumber() != $claim->getPolicy()->getPolicyNumber()) {
                    throw new \Exception(sprintf(
                        'Unable to locate claim %s in db with number matching. (%s != %s)',
                        $directGroupClaim->claimNumber,
                        $directGroupClaim->getPolicyNumber(),
                        $claim->getPolicy()->getPolicyNumber()
                    ));
                }
                $this->mailer->sendTemplate(
                    sprintf('Claim Number Update'),
                    'tech+ops@so-sure.com',
                    'AppBundle:Email:davies/incorrectClaimNumber.html.twig',
                    [
                        'claim' => $claim,
                        'claimNumber' => $directGroupClaim->claimNumber,
                        'policyNumber' => $directGroupClaim->getPolicyNumber(),
                    ]
                );
                $claim->setNumber($directGroupClaim->claimNumber, true);
            }
        }

        return $claim;
    }

    /**
     * @param DirectGroupHandlerClaim $directGroupClaim
     * @param boolean                 $skipImeiUpdate
     * @return bool
     * @throws \Exception
     */
    public function saveClaim(DirectGroupHandlerClaim $directGroupClaim, $skipImeiUpdate)
    {
        if ($directGroupClaim->hasError()) {
            throw new \Exception(sprintf(
                'Claim %s has error status. Skipping, but claim should not be in the export most likely.',
                $directGroupClaim->claimNumber
            ));
        }
        $claim = $this->getClaim($directGroupClaim);
        if (!$claim) {
            throw new \Exception(sprintf('Unable to locate claim %s in db', $directGroupClaim->claimNumber));
        } elseif ($claim->getHandlingTeam() != Claim::TEAM_DIRECT_GROUP) {
            $msg = sprintf(
                'Claim %s is being processed by %s, not direct group. Skipping direct group import.',
                $directGroupClaim->claimNumber,
                $claim->getHandlingTeam()
            );
            $this->sosureActions[$directGroupClaim->claimNumber][] = $msg;

            return false;
        }

        $this->validateClaimDetails($claim, $directGroupClaim);

        if ($claim->getType() != $directGroupClaim->getClaimType()) {
            throw new \Exception(sprintf('Claims type does not match for claim %s', $directGroupClaim->claimNumber));
        }
        if ($directGroupClaim->getClaimStatus()) {
            $claim->setStatus($directGroupClaim->getClaimStatus());
        } elseif ($directGroupClaim->isApproved() && $claim->getStatus() == Claim::STATUS_INREVIEW) {
            $claim->setStatus(Claim::STATUS_APPROVED);
        }

        $claim->setExcess($directGroupClaim->excess);
        $claim->setIncurred($directGroupClaim->getIncurred());
        $claim->setClaimHandlingFees($directGroupClaim->handlingFees);
        $claim->setReservedValue($directGroupClaim->reserved);
        $claim->setTotalIncurred($directGroupClaim->totalIncurred);

        $claim->setAccessories($directGroupClaim->accessories);
        $claim->setUnauthorizedCalls($directGroupClaim->unauthorizedCalls);
        $claim->setPhoneReplacementCost($directGroupClaim->phoneReplacementCost);

        if (in_array($claim->getStatus(), [Claim::STATUS_APPROVED, Claim::STATUS_SETTLED])
            && !$claim->getApprovedDate()) {
            // for claims without replacement date, the replacement should have occurred yesterday
            // for cases where its been forgotten, the business day should be 1 day prior to the received date
            $yesterday = \DateTime::createFromFormat('U', time());
            if ($directGroupClaim->replacementReceivedDate) {
                $yesterday = clone $directGroupClaim->replacementReceivedDate;
            }
            $yesterday = $this->subBusinessDays($yesterday, 1);

            $claim->setApprovedDate($yesterday);
        }

        $claim->setReplacementImei($directGroupClaim->replacementImei);
        $claim->setReplacementReceivedDate($directGroupClaim->replacementReceivedDate);
        $claim->setReplacementPhoneDetails($directGroupClaim->getReplacementPhoneDetails());

        $validator = new AlphanumericSpaceDotValidator();
        $claim->setDescription($validator->conform($directGroupClaim->lossDescription));
        $claim->setLocation($directGroupClaim->location);

        $claim->setClosedDate($directGroupClaim->dateClosed);
        $claim->setCreatedDate($directGroupClaim->dateCreated);
        $claim->setNotificationDate($directGroupClaim->notificationDate);
        $claim->setLossDate($directGroupClaim->lossDate);

        $claim->setShippingAddress($directGroupClaim->shippingAddress);

        $claim->setInitialSuspicion($directGroupClaim->initialSuspicion);
        $claim->setFinalSuspicion($directGroupClaim->finalSuspicion);

        $claim->setSupplier(
            $directGroupClaim->isReplacementRepaired() ?
            $directGroupClaim->repairSupplier : $directGroupClaim->replacementSupplier
        );
        $claim->setSupplierStatus($directGroupClaim->supplierStatus);

        $this->updatePolicy($claim, $directGroupClaim, $skipImeiUpdate);

        $errors = $this->validator->validate($claim);
        if (count($errors) > 0) {
            //\Doctrine\Common\Util\Debug::dump($errors, 3);
            $this->logger->error(sprintf(
                'Claim %s/%s (status: %s) failed validation. Discarding updates. Error: %s',
                $claim->getId(),
                $directGroupClaim->claimNumber,
                $claim->getStatus(),
                json_encode($errors)
            ));
            $this->dm->clear();
        }

        $this->dm->flush();

        $this->postValidateClaimDetails($claim, $directGroupClaim);

        $this->claimsService->processClaim($claim);

        // Only for active/unpaid policies with a theft/lost claim that have been repudiated
        if ($directGroupClaim->getClaimStatus() === Claim::STATUS_DECLINED &&
            in_array($claim->getType(), [Claim::TYPE_LOSS, Claim::TYPE_THEFT]) &&
            in_array($claim->getPolicy()->getStatus(), [Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID])) {
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
     * @param Claim                   $claim
     * @param DirectGroupHandlerClaim $directGroupClaim
     * @throws \Exception
     */
    public function validateClaimDetails(Claim $claim, DirectGroupHandlerClaim $directGroupClaim)
    {
        if (mb_strtolower($claim->getPolicy()->getPolicyNumber()) !=
            mb_strtolower($directGroupClaim->getPolicyNumber())) {
            throw new \Exception(sprintf(
                'Claim %s does not match policy number %s',
                $directGroupClaim->claimNumber,
                $directGroupClaim->getPolicyNumber()
            ));
        }

        if ($directGroupClaim->replacementImei && !$this->isImei($directGroupClaim->replacementImei)) {
            throw new \Exception(sprintf('Invalid replacement imei %s', $directGroupClaim->replacementImei));
        }

        if ($directGroupClaim->replacementImei && in_array($directGroupClaim->getClaimStatus(), [
            Claim::STATUS_DECLINED,
            Claim::STATUS_WITHDRAWN
        ])) {
            throw new \Exception(sprintf(
                'Claim %s has a replacement IMEI Number, yet has a withdrawn/declined status',
                $directGroupClaim->claimNumber
            ));
        }

        if ($directGroupClaim->replacementReceivedDate &&
            $directGroupClaim->replacementReceivedDate < $directGroupClaim->lossDate) {
            throw new \Exception(sprintf(
                'Claim %s has a replacement received date prior to loss date',
                $directGroupClaim->claimNumber
            ));
        }

        $now = \DateTime::createFromFormat('U', time());
        if ($directGroupClaim->isOpen() ||
            ($directGroupClaim->dateClosed && $directGroupClaim->dateClosed->diff($now)->days < 5)) {
            // lower case & remove title
            $insuredName = mb_strtolower($directGroupClaim->insuredName);
            foreach (['Mr. ', 'Mr ', 'Mrs. ', 'Mrs ', 'Miss '] as $title) {
                $insuredName = str_replace($title, '', $insuredName);
            }
            similar_text(mb_strtolower($claim->getPolicy()->getUser()->getName()), $insuredName, $percent);

            if ($percent < 50 && !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_NAME_MATCH)) {
                throw new \Exception(sprintf(
                    'Claim %s: %s does not match expected insuredName %s (match %0.1f)',
                    $directGroupClaim->claimNumber,
                    $directGroupClaim->insuredName,
                    $claim->getPolicy()->getUser()->getName(),
                    $percent
                ));
            } elseif ($percent < 75 && !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_NAME_MATCH)) {
                $msg = sprintf(
                    'Claim %s: %s does not match expected insuredName %s (match %0.1f)',
                    $directGroupClaim->claimNumber,
                    $directGroupClaim->insuredName,
                    $claim->getPolicy()->getUser()->getName(),
                    $percent
                );
                $this->warnings[$directGroupClaim->claimNumber][] = $msg;
            }

            if ($directGroupClaim->riskPostCode && $claim->getPolicy()->getUser()->getBillingAddress() &&
                !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_POSTCODE)) {
                if (!$this->postcodeCompare(
                    $claim->getPolicy()->getUser()->getBillingAddress()->getPostcode(),
                    $directGroupClaim->riskPostCode
                )) {
                    $msg = sprintf(
                        'Claim %s: %s does not match expected postcode %s',
                        $directGroupClaim->claimNumber,
                        $directGroupClaim->riskPostCode,
                        $claim->getPolicy()->getUser()->getBillingAddress()->getPostcode()
                    );
                    $this->warnings[$directGroupClaim->claimNumber][] = $msg;
                }
            }
        }

        /** @var PhonePolicy $phonePolicy */
        $phonePolicy = $claim->getPolicy();
        if (!$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_REPLACEMENT_COST_HIGHER) &&
            $directGroupClaim->phoneReplacementCost > $phonePolicy->getPhone()->getInitialPrice()) {
            $msg = sprintf(
                'Device replacement cost for claim %s is greater than initial price of the device',
                $directGroupClaim->claimNumber
            );
            $this->warnings[$directGroupClaim->claimNumber][] = $msg;
        }
        // Open Non-Warranty Claims are expected to either have a total incurred value or a reserved value
        if ($directGroupClaim->isOpen() && !$directGroupClaim->isClaimWarranty() &&
            $this->areEqualToTwoDp($directGroupClaim->totalIncurred, 0) &&
            $this->areEqualToTwoDp($directGroupClaim->getReserved(), 0)) {
            $msg = sprintf('Claim %s does not have a reserved value', $directGroupClaim->claimNumber);
            $this->errors[$directGroupClaim->claimNumber][] = $msg;
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

        $isExcessValueCorrect = $directGroupClaim->isExcessValueCorrect($claim);

        // if withdrawn and no actual need to validate in those cases
        if (!$isExcessValueCorrect &&
            !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_INCORRECT_EXCESS) &&
            !in_array($directGroupClaim->getClaimStatus(), [
                Claim::STATUS_DECLINED,
                Claim::STATUS_WITHDRAWN
            ])
        ) {
            $msg = sprintf(
                'Claim %s does not have the correct excess value. Expected %0.2f Actual %0.2f for %s/%s/%s/%s',
                $directGroupClaim->claimNumber,
                $directGroupClaim->getExpectedExcessValue($claim),
                $directGroupClaim->excess,
                $directGroupClaim->getClaimType(),
                $directGroupClaim->getClaimStatus(),
                $validated ? 'validated pic-sure' : 'not validated pic-sure',
                $phonePolicy->isPicSurePolicy() ? 'pic-sure policy' : 'non pic-sure policy'
            );
            $this->errors[$directGroupClaim->claimNumber][] = $msg;
        }

        if ($directGroupClaim->isIncurredValueCorrect() === false) {
            $msg = sprintf(
                'Claim %s does not have the correct incurred value. Expected %0.2f Actual %0.2f',
                $directGroupClaim->claimNumber,
                $directGroupClaim->getExpectedIncurred(),
                $directGroupClaim->getIncurred()
            );
            // seems to be an issue with small difference in the incurred value related to receipero fees
            // if under £2, then assume that to be the case and move to the fees section
            if (abs($directGroupClaim->getExpectedIncurred() - $directGroupClaim->getIncurred()) < 2) {
                $this->fees[$directGroupClaim->claimNumber][] = $msg;
            } else {
                $this->errors[$directGroupClaim->claimNumber][] = $msg;
            }
        }

        $approvedDate = null;
        if ($claim->getApprovedDate()) {
            $approvedDate = $this->startOfDay(clone $claim->getApprovedDate());
        } elseif ($directGroupClaim->replacementReceivedDate) {
            $approvedDate = $this->startOfDay(clone $directGroupClaim->replacementReceivedDate);
        }
        if ($directGroupClaim->isClosed(true)) {
            if ($directGroupClaim->isPhoneReplacementCostCorrect() === false) {
                $msg = sprintf(
                    'Claim %s does not have the correct phone replacement cost. Expected > 0 Actual %0.2f',
                    $directGroupClaim->claimNumber,
                    $directGroupClaim->phoneReplacementCost
                );
                $this->errors[$directGroupClaim->claimNumber][] = $msg;
            }
        }

        if ($directGroupClaim->isClosed(true) && $directGroupClaim->reserved > 0) {
            $msg = sprintf(
                'Claim %s is closed, yet still has a reserve fee.',
                $directGroupClaim->claimNumber
            );
            $this->errors[$directGroupClaim->claimNumber][] = $msg;
        }

        if (!$claim->getReplacementReceivedDate() && $directGroupClaim->replacementReceivedDate) {
            // We should be notified the next day when a replacement device is delivered
            // so we can follow up with our customer.
            // DG takes 3 days for some suppliers
            $ago = \DateTime::createFromFormat('U', time());
            $ago = $this->subBusinessDays($ago, 3);

            if ($directGroupClaim->replacementReceivedDate < $ago) {
                $msg = sprintf(
                    'Claim %s has a delayed replacement date (%s) which is more than 3 business days ago (%s)',
                    $directGroupClaim->claimNumber,
                    $directGroupClaim->replacementReceivedDate->format(\DateTime::ATOM),
                    $ago->format(\DateTime::ATOM)
                );
                $this->warnings[$directGroupClaim->claimNumber][] = $msg;
            }
        }

        $twoWeekAgo = \DateTime::createFromFormat('U', time());
        $twoWeekAgo = $twoWeekAgo->sub(new \DateInterval('P2W'));
        if ($claim->getApprovedDate() && in_array($directGroupClaim->getClaimStatus(), [
            Claim::STATUS_DECLINED,
            Claim::STATUS_WITHDRAWN
        ])) {
            $msg = sprintf(
                'Claim %s was previously approved, however is now withdrawn/declined. SO-SURE to remove approved date',
                $directGroupClaim->claimNumber
            );
            $this->sosureActions[$directGroupClaim->claimNumber][] = $msg;
        } elseif ($claim->getApprovedDate() && !$directGroupClaim->isApproved()) {
            $msg = sprintf(
                'Claim %s was previously approved, however no longer appears to be. SO-SURE to remove approved date',
                $directGroupClaim->claimNumber
            );
            $this->sosureActions[$directGroupClaim->claimNumber][] = $msg;
        } elseif ($claim->getApprovedDate() && $claim->getApprovedDate() <= $twoWeekAgo) {
            $items = [];
            if (!$directGroupClaim->replacementReceivedDate) {
                $items[] = 'received date';
            }
            if (!$directGroupClaim->replacementImei && !$directGroupClaim->isReplacementRepaired() &&
                !in_array('replacementImei', $directGroupClaim->unobtainableFields)) {
                $items[] = 'imei';
            }
            if (!$directGroupClaim->replacementMake || !$directGroupClaim->replacementModel) {
                $items[] = 'phone';
            }
            if (count($items) > 0) {
                $msg = sprintf(
                    'Claim %s was approved over 2 weeks ago (%s), however, the replacement data not recorded (%s).',
                    $directGroupClaim->claimNumber,
                    $claim->getApprovedDate()->format(\DateTime::ATOM),
                    implode('; ', $items)
                );
                $this->errors[$directGroupClaim->claimNumber][] = $msg;
            }
        } elseif ($claim->isClosed() && $claim->getStatus() != $directGroupClaim->getClaimStatus()) {
            $msg = sprintf(
                'Claim %s was previously closed (%s), however status is now %s (%s). SO-SURE to investigate',
                $directGroupClaim->claimNumber,
                $claim->getStatus(),
                $directGroupClaim->getClaimStatus() ?: 'open',
                $directGroupClaim->status
            );
            $this->sosureActions[$directGroupClaim->claimNumber][] = $msg;
        }

        if (!$directGroupClaim->replacementImei && !$directGroupClaim->isReplacementRepaired() &&
            in_array('replacementImei', $directGroupClaim->unobtainableFields)) {
            $msg = sprintf(
                'Claim %s does not have a replacement IMEI - unobtainable. Contact customer if possible.',
                $directGroupClaim->claimNumber
            );
            $this->warnings[$directGroupClaim->claimNumber][] = $msg;
        }

        if (!$directGroupClaim->replacementImei && !$directGroupClaim->isReplacementRepaired() &&
            $directGroupClaim->getClaimStatus() == Claim::STATUS_SETTLED) {
            $msg = sprintf(
                'Claim %s is settled without a replacement imei.',
                $directGroupClaim->claimNumber
            );
            $this->errors[$directGroupClaim->claimNumber][] = $msg;
        }

        if ($directGroupClaim->isOpen() && $claim->getPhonePolicy() && $directGroupClaim->replacementImei &&
            $directGroupClaim->replacementImei == $claim->getPhonePolicy()->getImei() && (
            !$directGroupClaim->replacementMake || !$directGroupClaim->replacementModel)) {
            // @codingStandardsIgnoreStart
            $msg = sprintf(
                'Claim %s has a replacement imei that matches the policy but is missing a replacement make and/or model. This is likely to be a data entry mistake.',
                $directGroupClaim->claimNumber
            );
            // @codingStandardsIgnoreEnd
            $this->errors[$directGroupClaim->claimNumber][] = $msg;
        }

        $threeMonthsAgo = \DateTime::createFromFormat('U', time());
        $threeMonthsAgo = $threeMonthsAgo->sub(new \DateInterval('P3M'));
        if ($directGroupClaim->isOpen() && $directGroupClaim->replacementReceivedDate &&
            $directGroupClaim->replacementReceivedDate < $threeMonthsAgo) {
            $msg = sprintf(
                'Claim %s should be closed. Replacement was delivered more than 3 months ago on %s.',
                $directGroupClaim->claimNumber,
                $directGroupClaim->replacementReceivedDate->format(\DateTime::ATOM)
            );
            $this->errors[$directGroupClaim->claimNumber][] = $msg;
        }

        if (!isset($directGroupClaim->initialSuspicion)) {
            $msg = sprintf(
                'Claim %s does not have initialSuspicion flag set.',
                $directGroupClaim->claimNumber
            );
            $this->warnings[$directGroupClaim->claimNumber][] = $msg;
        }

        if ($directGroupClaim->getClaimStatus() != Claim::STATUS_WITHDRAWN &&
            mb_strlen($directGroupClaim->lossDescription) < self::MIN_LOSS_DESCRIPTION_LENGTH) {
            $msg = sprintf(
                'Claim %s does not have a detailed loss description',
                $directGroupClaim->claimNumber
            );
            $this->warnings[$directGroupClaim->claimNumber][] = $msg;
        }

        if ($directGroupClaim->isReplacementRepaired() && mb_strlen($directGroupClaim->repairSupplier) == 0) {
            $msg = sprintf(
                'Claim %s is a repaired claim, but no supplier set',
                $directGroupClaim->claimNumber
            );
            $this->warnings[$directGroupClaim->claimNumber][] = $msg;
        }

        if ($directGroupClaim->isReplacementRepaired() && mb_strlen($directGroupClaim->replacementImei) > 0) {
            $msg = sprintf(
                'Claim %s is a repaired claim, but replacement imei is present',
                $directGroupClaim->claimNumber
            );
            $this->errors[$directGroupClaim->claimNumber][] = $msg;
        }
    }

    public function postValidateClaimDetails(Claim $claim, DirectGroupHandlerClaim $directGroupClaim)
    {
        if ($claim->getApprovedDate() && $claim->getReplacementReceivedDate() &&
            $claim->getApprovedDate() > $claim->getReplacementReceivedDate()) {
            $msg = sprintf(
                'Claim %s has an approved date (%s) more recent than the received date (%s)',
                $directGroupClaim->claimNumber,
                $claim->getApprovedDate()->format(\DateTime::ATOM),
                $claim->getReplacementReceivedDate()->format(\DateTime::ATOM)
            );
            $this->warnings[$directGroupClaim->claimNumber][] = $msg;
        }

        // Should be in post validate in case the record fails import
        if (!$claim->getReplacementPhone() && $claim->getReplacementPhoneDetails() &&
            $claim->getStatus() == Claim::STATUS_SETTLED) {
            $msg = sprintf(
                'Claim %s is settled without a replacement phone being set. SO-SURE to set replacement phone.',
                $directGroupClaim->claimNumber
            );
            $this->sosureActions[$directGroupClaim->claimNumber][] = $msg;
        }

        if (count($directGroupClaim->unobtainableFields) > 0) {
            $msg = sprintf(
                'The following fields are noted as unobtainable: %s',
                json_encode($directGroupClaim->unobtainableFields)
            );
            $this->warnings[$directGroupClaim->claimNumber][] = $msg;
        }
    }

    /**
     * @param Claim                   $claim
     * @param DirectGroupHandlerClaim $directGroupClaim
     * @param boolean                 $skipImeiUpdate
     * @throws \Exception
     */
    public function updatePolicy(Claim $claim, DirectGroupHandlerClaim $directGroupClaim, $skipImeiUpdate)
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
                    ['policy' => $policy, 'daviesClaim' => $directGroupClaim, 'skipImeiUpdate' => $skipImeiUpdate]
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
                    ['policy' => $policy, 'daviesClaim' => $directGroupClaim, 'skipImeiUpdate' => true]
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
                    $directGroupClaim->claimNumber
                );
                $this->errors[$directGroupClaim->claimNumber][] = $msg;
            }
        }
    }
}
