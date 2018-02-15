<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Document\File\PicSureFile;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PhonePolicyRepository")
 * @Gedmo\Loggable
 */
class PhonePolicy extends Policy
{
    use CurrencyTrait;

    const STANDARD_VALUE = 10;
    const AGED_VALUE = 2;
    const NETWORK_CLAIM_VALUE = 2;
    const PROMO_LAUNCH_VALUE = 5;

    const PICSURE_STATUS_PREAPPROVED = 'preapproved';
    const PICSURE_STATUS_APPROVED = 'approved';
    const PICSURE_STATUS_REJECTED = 'rejected';
    const PICSURE_STATUS_MANUAL = 'manual';
    const PICSURE_STATUS_INVALID = 'invalid';
    const PICSURE_STATUS_DISABLED = 'disabled';

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
     * @MongoDB\Date()
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
     * @Assert\Choice({"preapproved", "approved", "invalid", "rejected", "manual"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $picSureStatus;

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
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $picSureApprovedDate;

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

    public function setPhone(Phone $phone, \DateTime $date = null)
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
            $price = $phone->getCurrentPhonePrice($date);
            $this->setPremium($price->createPremium($additionalPremium, $date));
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
            $this->setImeiReplacementDate(new \DateTime());
        }
        $this->setImei($imei);

        if ($this->getNextPolicy()) {
            $this->getNextPolicy()->adjustImei($imei, $setReplacementDate);
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
        if (!$certId || strlen(trim($certId)) == 0) {
            return;
        }

        $now = new \DateTime();
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
        if (!$this->getUser()) {
            throw new \Exception('Policy is missing a user');
        }

        return (int) ceil($this->getMaxPot() / $this->getTotalConnectionValue($date));
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
        $policy->setPhone($this->getPhone());
        $policy->setImei($this->getImei());
        $policy->setSerialNumber($this->getSerialNumber());

        // make sure ipt rate is set to ipt rate at the start of the policy
        $policy->validatePremium(true, $startDate);
        if ($terms->isPicSureEnabled()) {
            $policy->setPicSureStatus(self::PICSURE_STATUS_PREAPPROVED);
        }
    }

    public function setPolicyDetailsForRepurchase(Policy $policy, \DateTime $startDate)
    {
        $policy->setPhone($this->getPhone());
        $policy->setImei($this->getImei());
        $policy->setSerialNumber($this->getSerialNumber());

        // make sure ipt rate is set to ipt rate at the start of the policy
        $policy->validatePremium(true, $startDate);
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

    public function getPicSureStatus()
    {
        if (!$this->picSureStatus && $this->getPolicyTerms() && !$this->getPolicyTerms()->isPicSureEnabled()) {
            return self::PICSURE_STATUS_DISABLED;
        }

        return $this->picSureStatus;
    }

    public function getPicSureStatusForApi()
    {
        $status = $this->getPicSureStatus();
        if ($status == self::PICSURE_STATUS_PREAPPROVED) {
            return self::PICSURE_STATUS_APPROVED;
        }

        return $status;
    }
    
    public function setPicSureStatus($picSureStatus)
    {
        $this->picSureStatus = $picSureStatus;
        if ($picSureStatus == self::PICSURE_STATUS_APPROVED && !$this->getPicSureApprovedDate()) {
            $this->setPicSureApprovedDate(new \DateTime());
        }
    }

    public function isPicSureValidated()
    {
        return $this->getPicSureStatus() == self::PICSURE_STATUS_APPROVED;
    }

    public function getPolicyPicSureFiles()
    {
        return $this->getPolicyFilesByType(PicSureFile::class);
    }

    public function isSameInsurable(Policy $policy)
    {
        if ($policy instanceof PhonePolicy) {
            return $this->getImei() == $policy->getImei();
        } else {
            return false;
        }
    }

    public function toApiArray()
    {
        $picSureEnabled = $this->getPolicyTerms() && $this->getPolicyTerms()->isPicSureEnabled();
        $picSureValidated = $this->isPicSureValidated();

        return array_merge(parent::toApiArray(), [
            'phone_policy' => [
                'imei' => $this->getImei(),
                'phone' => $this->getPhone() ? $this->getPhone()->toApiArray() : null,
                'name' => $this->getName() && strlen($this->getName()) > 0 ?
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
