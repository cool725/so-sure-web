<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Document\File\ImeiFile;
use AppBundle\Document\File\PicSureFile;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PhonePolicyRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class PhonePolicy extends Policy
{
    use CurrencyTrait;
    use ImeiTrait;

    const STANDARD_VALUE = 10;
    const AGED_VALUE = 2;
    const NETWORK_CLAIM_VALUE = 2;
    const PROMO_LAUNCH_VALUE = 5;

    /**
     * non-pic-sure policy was renewed and so no need to pic-sure the phone, but should reduce the excess
     */
    const PICSURE_STATUS_PREAPPROVED = 'preapproved';

    /**
     * pic-sure is approved
     */
    const PICSURE_STATUS_APPROVED = 'approved';

    /**
     * pic-sure is not valid - screen broken
     */
    const PICSURE_STATUS_REJECTED = 'rejected';

    /**
     * Needs manual processing
     */
    const PICSURE_STATUS_MANUAL = 'manual';

    /**
     * photo checked, but uncertain
     */
    const PICSURE_STATUS_INVALID = 'invalid';

    /**
     * pic-sure does not apply to this policy
     */
    const PICSURE_STATUS_DISABLED = 'disabled';

    /**
     * if phone replaced due to claim, then new phone doesn't neeed pic-sure
     */
    const PICSURE_STATUS_CLAIM_APPROVED = 'claim-approved';

    /**
     * if policy has a fnol, submitted or in-review claim
     */
    const PICSURE_STATUS_CLAIM_PREVENTED = 'claim-prevented';

    const MAKEMODEL_VALID_SERIAL = 'valid-serial';
    const MAKEMODEL_VALID_IMEI = 'valid-imei';
    const MAKEMODEL_NO_MODELS = 'no-models';
    const MAKEMODEL_NO_MEMORY = 'no-memory';
    const MAKEMODEL_NO_MAKES = 'no-makes';
    const MAKEMODEL_MULTIPLE_MAKES = 'multiple-makes';
    const MAKEMODEL_EMPTY_MAKES = 'empty-makes';
    const MAKEMODEL_MEMORY_MISMATCH = 'memory-mismatch';
    const MAKEMODEL_MISSING_RESPONSE = 'missing-response';
    const MAKEMODEL_NO_MODEL_REFERENCE = 'no-model-reference';
    const MAKEMODEL_MAKE_MODEL_MEMORY_MISMATCH = 'make-model-memory-mismatch';
    const MAKEMODEL_MODEL_MISMATCH = 'model-mismatch';
    const MAKEMODEL_DEVICE_NOT_FOUND = 'device-not-found';

    use ArrayToApiArrayTrait;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @Gedmo\Versioned
     * @var Phone
     */
    protected $phone;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $phoneVerified;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $screenVerified;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $phoneData;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $imei;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $detectedImei;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $imeiReplacementDate;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $serialNumber;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $modelNumber;

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $checkmendCerts = array();

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $name;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $imeiCircumvention;

    /**
     * @Assert\Choice({"preapproved", "approved", "invalid", "rejected", "manual", "disabled", "claim-approved"},
     *     strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $picSureStatus;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Claim")
     * @Gedmo\Versioned
     */
    protected $picSureClaimApprovedClaim;

    /**
     * @Assert\Choice({"valid-serial", "valid-imei", "no-models", "no-memory", "no-makes",
     *     "multiple-makes", "empty-makes", "memory-mismatch", "missing-response", "no-model-reference",
     *     "make-model-memory-mismatch", "model-mismatch", "device-not-found"}, strict=false)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $makeModelValidatedStatus;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $picSureApprovedDate;

    /**
     * @MongoDB\EmbedMany(targetDocument="Coordinates")
     */
    protected $picsureLocations;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $picSureCircumvention;

    /**
     * @return Phone
     */
    public function getPhone()
    {
        return $this->phone;
    }

    public function setMakeModelValidatedStatus($makeModelValidatedStatus)
    {
        $this->makeModelValidatedStatus = $makeModelValidatedStatus;
    }

    public function getMakeModelValidatedStatus()
    {
        return $this->makeModelValidatedStatus;
    }

    public function setPhone(Phone $phone, \DateTime $date = null, $validateExcess = true)
    {
        $this->phone = $phone;
        if (!$phone->getCurrentPhonePrice()) {
            throw new \Exception('Phone must have a price');
        }

        // Only set premium if not already present
        if (!$this->getPremium()) {
            $additionalPremium = null;
            if ($this->getUser()) {
                $additionalPremium = $this->getUser()->getAdditionalPremium();
            }
            /** @var PhonePrice $price */
            $price = $phone->getCurrentPhonePrice($date);
            $this->setPremium($price->createPremium($additionalPremium, $date));
            // in the normal flow we should have policy terms before setting the phone
            // however, many test cases do not have it
            if ($this->getPolicyTerms() && $validateExcess) {
                $this->validateAllowedExcess();
            }
        }
    }

    public function validateAllowedExcess()
    {
        parent::validateAllowedExcess();

        /** @var PhonePremium $phonePremium */
        $phonePremium = $this->getPremium();
        if (!$phonePremium || !$phonePremium->getPicSureExcess()) {
            return;
        }

        if ($this->getPolicyTerms()->isPicSureEnabled() &&
            !$this->getPolicyTerms()->isAllowedPicSureExcess($phonePremium->getPicSureExcess())) {
            throw new \Exception(sprintf(
                'Unable to set phone for policy %s as pic-sure excess values do not match policy terms.',
                $this->getId()
            ));
        }
    }

    public function getPhoneVerified()
    {
        return $this->phoneVerified;
    }

    public function setPhoneVerified($phoneVerified)
    {
        // If a phone is verified successfully at any point in time, then the verified flag should remain set
        if ($this->getPhoneVerified() !== true) {
            $this->phoneVerified = $phoneVerified;
        }
    }

    public function getScreenVerified()
    {
        return $this->screenVerified;
    }

    public function setScreenVerified($screenVerified)
    {
        // If a phone is verified successfully at any point in time, then the verified flag should remain set
        if ($this->getScreenVerified() !== true) {
            $this->screenVerified = $screenVerified;
        }
    }

    public function getImei()
    {
        return $this->imei;
    }

    public function setImei($imei)
    {
        $this->imei = $imei;
    }

    public function adjustImei($imei, $setReplacementDate = true)
    {
        if ($setReplacementDate && $this->imei && $imei != $this->imei) {
            $this->setImeiReplacementDate(\DateTime::createFromFormat('U', time()));
        }
        $this->setImei($imei);

        /** @var PhonePolicy $nextPolicy */
        $nextPolicy = $this->getNextPolicy();
        if ($nextPolicy) {
            $nextPolicy->adjustImei($imei, $setReplacementDate);
        }
    }

    public function getDetectedImei()
    {
        return $this->detectedImei;
    }

    public function setDetectedImei($detectedImei)
    {
        $this->detectedImei = $detectedImei;
    }

    public function getImeiReplacementDate()
    {
        return $this->imeiReplacementDate;
    }

    public function setImeiReplacementDate(\DateTime $imeiReplacementDate = null)
    {
        $this->imeiReplacementDate = $imeiReplacementDate;
    }

    public function getPicSureApprovedDate()
    {
        return $this->picSureApprovedDate;
    }

    public function setPicSureApprovedDate(\DateTime $picSureApprovedDate)
    {
        $this->picSureApprovedDate = $picSureApprovedDate;
    }

    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = $serialNumber;
    }

    public function getModelNumber()
    {
        return $this->modelNumber;
    }

    public function setModelNumber($modelNumber)
    {
        $this->modelNumber = $modelNumber;
    }

    public function isValidAppleSerialNumber()
    {
        return $this->isAppleSerialNumber($this->getSerialNumber());
    }

    public function getPhoneData()
    {
        return $this->phoneData;
    }

    public function setPhoneData($phoneData)
    {
        $this->phoneData = $phoneData;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getDefaultName()
    {
        if ($this->getUser() && $this->getPhone()) {
            return sprintf("%s's %s", ucfirst($this->getUser()->getFirstName()), $this->getPhone()->getModel());
        } elseif ($this->getPhone()) {
            return sprintf("%s", $this->getPhone()->getModel());
        } else {
            return null;
        }
    }

    public function getCheckmendCerts()
    {
        return $this->checkmendCerts;
    }

    public function getCheckmendCertsAsArray($onlyClaims = null)
    {
        $certs = [];
        foreach ($this->getCheckmendCerts() as $date => $data) {
            try {
                $actualData = unserialize($data);
                $includeCert = false;
                if ($actualData['certId'] == 'register') {
                    $includeCert = !$onlyClaims;
                } elseif ($onlyClaims && isset($actualData['claimId']) && $actualData['claimId']) {
                    $includeCert = true;
                } elseif ($onlyClaims === false && (!isset($actualData['claimId']) || !$actualData['claimId'])) {
                    $includeCert = true;
                }

                if ($includeCert) {
                    $certs[$date] = $actualData;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $certs;
    }

    public function addCheckmendCerts($key, $value)
    {
        $this->checkmendCerts[$key] = $value;
    }

    public function addCheckmendSerialData($response)
    {
        $this->addCheckmendCertData('serial', $response);
    }

    public function addCheckmendRegisterData($transactionId, $imei, $claim, $claimType)
    {
        $this->addCheckmendCertData(
            'register',
            ['imei' => $imei, 'transactionId' => $transactionId, 'claimType' => $claimType],
            $claim
        );
    }

    public function addCheckmendCertData($certId, $response, $claim = null)
    {
        if (!$certId || mb_strlen(trim($certId)) == 0) {
            return;
        }

        $now = \DateTime::createFromFormat('U', time());
        $data = [
            'certId' => $certId,
            'response' => $response,
        ];
        if ($claim) {
            $data['claimId'] = $claim->getId();
            $data['claimNumber'] = $claim->getNumber();
        }

        // in the case of an imei check & serial check running at the same time, just increment the later by a second
        $timestamp = $now->format('U');
        while (isset($this->checkmendCerts[$timestamp])) {
            $timestamp++;
        }
        $this->checkmendCerts[$timestamp] = serialize($data);
    }

    public function getTotalConnectionValue(\DateTime $date = null)
    {
        return $this->getConnectionValue($date) + $this->getPromoConnectionValue($date);
    }

    public function getTotalConnectionValueLimit()
    {
        return self::STANDARD_VALUE;
    }

    public function getConnectionValue(\DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return 0;
        }
        if (!$this->getUser()) {
            throw new \Exception('Policy is missing a user');
        }

        if ($this->hasMonetaryClaimed()) {
            // should never occur, but just in case
            return 0;
        } elseif ($this->hasMonetaryNetworkClaim()) {
            return self::NETWORK_CLAIM_VALUE;
        } elseif ($this->isPolicyWithin60Days($date)) {
            return self::STANDARD_VALUE;
        } elseif ($this->isBeforePolicyStarted($date)) {
            // Case for Salva's 10 minute buffer
            return self::STANDARD_VALUE;
        } elseif (in_array($this->getStatus(), [self::STATUS_PENDING_RENEWAL, self::STATUS_RENEWAL])) {
            // Renewals should assume standard (max) value
            return self::STANDARD_VALUE;
        } else {
            return self::AGED_VALUE;
        }
    }

    public function getPromoConnectionValue(\DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return 0;
        }
        if (!$this->getUser()) {
            throw new \Exception('Policy is missing a user');
        }

        // any claims should have a 0 value
        if ($this->hasMonetaryClaimed() || $this->hasMonetaryNetworkClaim()) {
            return 0;
        }

        // Extra Case for Salva's 10 minute buffer
        if ($this->isPolicyWithin60Days($date) || $this->isBeforePolicyStarted($date)) {
            if ($this->isPreLaunchPolicy()) {
                return self::PROMO_LAUNCH_VALUE;
            }
        }

        return 0;
    }

    public function getAllowedConnectionValue(\DateTime $date = null)
    {
        return $this->getAllowedStandardOrPromoConnectionValue(false, $date);
    }

    public function getAllowedPromoConnectionValue(\DateTime $date = null)
    {
        return $this->getAllowedStandardOrPromoConnectionValue(true, $date);
    }

    private function getMaxPotRemainder()
    {
        $potValue = $this->getPotValue();
        $maxPot = $this->getMaxPot();

        return $maxPot - $potValue;
    }

    private function getAllowedStandardOrPromoConnectionValue($promoCodeOnly, \DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return 0;
        }

        $connectionValue = $this->getConnectionValue($date);
        if ($promoCodeOnly) {
            $maxPromoPotRemainder = $this->getMaxPotRemainder() - $connectionValue;

            // If its the last connection, check that the initial bit of the connection value hasn't alredy been used up
            if ($maxPromoPotRemainder <= 0) {
                return 0;
            }

            // Get the promo connection value
            $connectionValue = $this->getPromoConnectionValue($date);

            // If its the last connection, then may be less than the full £15/£10/£2
            if ($connectionValue > $maxPromoPotRemainder) {
                $connectionValue = $maxPromoPotRemainder;
            }
        } else {
            // If its the last connection, then may be less than the full £15/£10/£2
            if ($this->getMaxPotRemainder() < $connectionValue) {
                $connectionValue = $this->getMaxPotRemainder();
            }
        }

        // Should never be the case, but ensure connectionValue isn't negative
        if ($connectionValue < 0) {
            $connectionValue = 0;
        }

        return $connectionValue;
    }

    public function getRemainingConnections(\DateTime $date = null)
    {
        return $this->getMaxConnections($date) - count($this->getConnections());
    }

    public function getMaxConnections(\DateTime $date = null)
    {
        if (!$this->isPolicy() || $this->areEqualToFourDp($this->getConnectionValue($date), 0)) {
            return 0;
        }
        return $this->getMaxConnectionsLimit($date);
    }

    public function getMaxConnectionsLimit(\DateTime $date = null)
    {
        if (!$this->getUser()) {
            throw new \Exception('Policy is missing a user');
        }

        return (int) ceil($this->getMaxPot() / $this->getTotalConnectionValueLimit());
    }

    private function isPreLaunchPolicy()
    {
        if ($this->getUser()->isPreLaunch() && $this->getStart()
            && $this->getStart() < new \DateTime('2017-09-01')) {
            return true;
        }

        return in_array($this->getPromoCode(), [self::PROMO_LAUNCH, self::PROMO_LAUNCH_FREE_NOV]);
    }

    public function getMaxPot()
    {
        if (!$this->isPolicy()) {
            return 0;
        }
        if (!$this->getUser()) {
            throw new \Exception('Policy is missing a user');
        }

        if ($this->isPreLaunchPolicy()) {
            // 100% of policy
            return $this->getPremium()->getYearlyPremiumPrice();
        } else {
            return $this->toTwoDp($this->getPremium()->getYearlyPremiumPrice() * 0.8);
        }
    }

    public function setPolicyDetailsForPendingRenewal(Policy $policy, \DateTime $startDate, PolicyTerms $terms)
    {
        /** @var PhonePolicy $phonePolicy */
        $phonePolicy = $policy;
        $phonePolicy->setPhone($this->getPhone());
        $phonePolicy->setImei($this->getImei());
        $phonePolicy->setSerialNumber($this->getSerialNumber());

        // make sure ipt rate is set to ipt rate at the start of the policy
        $phonePolicy->validatePremium(true, $startDate);
        if ($terms->isPicSureEnabled()) {
            $phonePolicy->setPicSureStatus(self::PICSURE_STATUS_PREAPPROVED);
        }
    }

    public function setPolicyDetailsForRepurchase(Policy $policy, \DateTime $startDate)
    {
        /** @var PhonePolicy $phonePolicy */
        $phonePolicy = $policy;
        $phonePolicy->setPhone($this->getPhone());
        $phonePolicy->setImei($this->getImei());
        $phonePolicy->setSerialNumber($this->getSerialNumber());

        // make sure ipt rate is set to ipt rate at the start of the policy
        $phonePolicy->validatePremium(true, $startDate);
    }

    /**
     * If the premium is initialized prior to an ipt rate change
     * and then created after, the IPT would be incorrect
     */
    public function validatePremium($adjust, \DateTime $date = null)
    {
        $phonePrice = $this->getPhone()->getCurrentPhonePrice($date);
        if (!$phonePrice) {
            throw new \UnexpectedValueException(sprintf('Missing phone price'));
        }
        $additionalPremium = null;
        if ($this->getUser()) {
            $additionalPremium = $this->getUser()->getAdditionalPremium();
        }
        $expectedPremium = $phonePrice->createPremium($additionalPremium, $date);

        if ($this->getPremium()!= $expectedPremium) {
            if ($adjust) {
                $this->setPremium($expectedPremium);
            } else {
                throw new InvalidPremiumException(sprintf(
                    'Ipt rate %f is not valid (should be %f)',
                    $this->getPremium()->getIptRate(),
                    $this->getCurrentIptRate($date)
                ));
            }
        }
    }

    public function getPolicyNumberPrefix()
    {
        return 'Mob';
    }

    public function getPolicyImeiFiles()
    {
        return $this->getPolicyFilesByType(ImeiFile::class);
    }

    public function getImeiCircumvention()
    {
        return $this->imeiCircumvention;
    }

    public function setImeiCircumvention($imeiCircumvention)
    {
        $this->imeiCircumvention = $imeiCircumvention;
    }

    public function isPicSurePolicy()
    {
        if (!$this->getPolicyTerms()) {
            return null;
        }

        return $this->getPolicyTerms()->isPicSureEnabled();
    }

    public function getPicSureStatus()
    {
        if (!$this->picSureStatus && !$this->isPicSurePolicy()) {
            return self::PICSURE_STATUS_DISABLED;
        }

        return $this->picSureStatus;
    }

    public function getPicSureClaimApprovedClaim()
    {
        return $this->picSureClaimApprovedClaim;
    }

    public function setPicSureClaimApprovedClaim(Claim $claim)
    {
        $this->picSureClaimApprovedClaim = $claim;
    }

    public function getPicSureStatusForApi()
    {
        $status = $this->getPicSureStatusWithClaims();
        if (in_array($status, [self::PICSURE_STATUS_PREAPPROVED, self::PICSURE_STATUS_CLAIM_APPROVED])) {
            return self::PICSURE_STATUS_APPROVED;
        }

        return $status;
    }
    
    public function getPicSureStatusWithClaims()
    {
        $status = $this->getPicSureStatus();

        if ($status === null || $status == self::PICSURE_STATUS_INVALID) {
            foreach ($this->getClaims() as $claim) {
                /** @var Claim $claim */
                if (in_array(
                    $claim->getStatus(),
                    [
                        Claim::STATUS_FNOL,
                        Claim::STATUS_SUBMITTED,
                        Claim::STATUS_INREVIEW,
                        Claim::STATUS_APPROVED,
                        Claim::STATUS_SETTLED,
                    ]
                ) && !$claim->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_ALLOW_PICSURE_REDO)) {
                    $status = self::PICSURE_STATUS_CLAIM_PREVENTED;
                    break;
                }
            }
        }

        return $status;
    }

    public function setPicSureStatus($picSureStatus, User $user = null)
    {
        $this->picSureStatus = $picSureStatus;
        if ($picSureStatus == self::PICSURE_STATUS_APPROVED && !$this->getPicSureApprovedDate()) {
            $this->setPicSureApprovedDate(\DateTime::createFromFormat('U', time()));
        }

        $picsureFiles = $this->getPolicyFilesByType(PicSureFile::class);
        if (count($picsureFiles) > 0) {
            $picsureFiles[0]->addMetadata('picsure-status', $picSureStatus);
            $now = \DateTime::createFromFormat('U', time());
            $picsureFiles[0]->addMetadata('picsure-status-date', $now->format(\DateTime::ATOM));
            if ($user) {
                $picsureFiles[0]->addMetadata('picsure-status-user-name', $user->getName());
                $picsureFiles[0]->addMetadata('picsure-status-user-id', $user->getId());
            }
        }

        foreach ($this->getClaims() as $claim) {
            /** @var Claim $claim */
            if (!$claim->isClosed(true)) {
                $claim->setExpectedExcess($this->getCurrentExcess());
            }
        }
    }

    public function isPicSureValidated()
    {
        return in_array($this->getPicSureStatus(), [
            self::PICSURE_STATUS_APPROVED,
            self::PICSURE_STATUS_PREAPPROVED,
            self::PICSURE_STATUS_CLAIM_APPROVED,
        ]);
    }

    public function isPicSureValidatedIncludingClaim(Claim $claim)
    {
        $validated = $this->isPicSureValidated();

        // After the initial import, once the pic-sure status changes to CLAIM-APPROVED
        // we need to check to see if the claim is the same
        if ($validated && $this->getPicSureClaimApprovedClaim() && $claim->getId()) {
            $validated = $this->getPicSureClaimApprovedClaim()->getId() != $claim->getId();
        }

        return $validated;
    }

    public function canAdjustPicSureStatusForClaim()
    {
        if (in_array($this->getPicSureStatus(), [
            PhonePolicy::PICSURE_STATUS_REJECTED,
            PhonePolicy::PICSURE_STATUS_INVALID,
            PhonePolicy::PICSURE_STATUS_MANUAL,
            null,
        ])) {
            return true;
        } else {
            return false;
        }
    }

    public function getPolicyPicSureFiles()
    {
        return $this->getPolicyFilesByType(PicSureFile::class);
    }

    public function getPicsureLocations()
    {
        return $this->picsureLocations;
    }

    public function setPicsureLocations($picsureLocations)
    {
        $this->picsureLocations = $picsureLocations;
    }

    public function addPicsureLocation(Coordinates $picsureLocation)
    {
        $this->picsureLocations[] = $picsureLocation;
    }

    public function getPicSureCircumvention()
    {
        return $this->picSureCircumvention;
    }

    public function setPicSureCircumvention($picSureCircumvention)
    {
        $this->picSureCircumvention = $picSureCircumvention;
    }

    public function isSameInsurable(Policy $policy)
    {
        if ($policy instanceof PhonePolicy) {
            return $this->getImei() == $policy->getImei();
        } else {
            return false;
        }
    }

    public function getExcessValue($type)
    {
        return Claim::getExcessValue($type, $this->isPicSureValidated(), $this->isPicSurePolicy());
    }

    public function getCurrentExcess()
    {
        /** @var PhonePremium $phonePremium */
        $phonePremium = $this->getPremium();
        if (!$phonePremium) {
            return null;
        }

        if ($this->isPicSureValidated()) {
            return $phonePremium->getPicSureExcess();
        } else {
            return $phonePremium->getExcess();
        }
    }

    public function toApiArray()
    {
        $picSureEnabled = $this->isPicSurePolicy();
        $picSureValidated = $this->isPicSureValidated();

        return array_merge(parent::toApiArray(), [
            'phone_policy' => [
                'imei' => $this->getImei(),
                'phone' => $this->getPhone() ? $this->getPhone()->toApiArray() : null,
                'name' => $this->getName() && mb_strlen($this->getName()) > 0 ?
                    $this->getName() :
                    $this->getDefaultName(),
                'picsure_status' => $this->getPicSureStatusForApi(),
                'excesses' => [
                    [
                        'type' => Claim::TYPE_LOSS,
                        'display' => 'Loss',
                        'amount' => Claim::getExcessValue(Claim::TYPE_LOSS, $picSureValidated, $picSureEnabled)
                    ],
                    [
                        'type' => Claim::TYPE_THEFT,
                        'display' => 'Theft',
                        'amount' => Claim::getExcessValue(Claim::TYPE_THEFT, $picSureValidated, $picSureEnabled)
                    ],
                    [
                        'type' => Claim::TYPE_DAMAGE,
                        'display' => 'Accidental Damage',
                        'amount' => Claim::getExcessValue(Claim::TYPE_DAMAGE, $picSureValidated, $picSureEnabled)
                    ],
                    [
                        'type' => Claim::TYPE_EXTENDED_WARRANTY,
                        'display' => 'Breakdown',
                        'amount' => Claim::getExcessValue(
                            Claim::TYPE_EXTENDED_WARRANTY,
                            $picSureValidated,
                            $picSureEnabled
                        )
                    ],
                ],
                'detected_imei' => $this->getDetectedImei(),
            ],
        ]);
    }
}
