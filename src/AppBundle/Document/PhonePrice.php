<?php

namespace AppBundle\Document;

use AppBundle\Document\Excess\PhoneExcess;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class PhonePrice extends Price
{
    use CurrencyTrait;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Excess\PhoneExcess")
     * @Gedmo\Versioned
     * @var PhoneExcess|null
     */
    protected $picSureExcess;

    public function setPicSureExcess(PhoneExcess $phoneExcess = null)
    {
        $this->picSureExcess = $phoneExcess;
    }

    /**
     * @return PhoneExcess|null
     */
    public function getPicSureExcess()
    {
        return $this->picSureExcess;
    }

    public function getMaxPot($isPromoLaunch = false)
    {
        if ($isPromoLaunch) {
            return $this->toTwoDp($this->getYearlyPremiumPrice());
        } else {
            return $this->toTwoDp($this->getYearlyPremiumPrice() * 0.8);
        }
    }

    public function getMaxConnections($promoAddition = 0, $isPromoLaunch = false)
    {
        return (int) ceil($this->getMaxPot($isPromoLaunch) / $this->getInitialConnectionValue($promoAddition));
    }

    public function getInitialConnectionValue($promoAddition = 0)
    {
        return PhonePolicy::STANDARD_VALUE + $promoAddition;
    }

    public function createPremium($additionalGwp = null, \DateTime $date = null)
    {
        $premium = new PhonePremium();
        $this->populatePremium($premium, $additionalGwp, $date);
        if ($this->getPicSureExcess()) {
            $premium->setPicSureExcess($this->getPicSureExcess());
        }

        return $premium;
    }

    public function toPriceArray(\DateTime $date = null)
    {
        return array_merge(parent::toPriceArray($date), [
            'picsure_excess' => $this->getPicSureExcess() ? $this->getPicSureExcess()->toApiArray() : null,
            'picsure_excess_detail' =>
                $this->getPicSureExcess() ? $this->getPicSureExcess()->toPriceArray()['detail'] : '??',
        ]);
    }
}
