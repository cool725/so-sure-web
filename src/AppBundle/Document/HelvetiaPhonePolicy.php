<?php

namespace AppBundle\Document;

use AppBundle\Document\Policy\Policy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Classes\Helvetia;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PhonePolicyRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class HelvetiaPhonePolicy extends PhonePolicy
{
    /**
     * @inheritDoc
     */
    public function getUnderwriterTimeZone()
    {
        return new \DateTimeZone("Europe/Zurich");
    }

    /**
     * @inheritDoc
     */
    public function getUnderwriterName()
    {
        return Helvetia::NAME;
    }

    /**
     * @inheritDoc
     */
    public function setTotalCommission($payment)
    {
        // TODO: this.
    }

    /**
     * @inheritDoc
     */
    public function getYearlyTotalCommission(): float
    {
        $premium = $this->getPremium();
        if (!$premium) {
            throw new \InvalidArgumentException(sprintf(
                'Commission cannot be calculated for Helvetia policy "%s" without premium',
                $this->getId()
            ));
        }
        // 20% of premium price.
        // TODO: Make sure this is the right proportion and it was not meant to be before tax.
        return $premium->getYearlyPremiumPrice() / 5;
    }

    /**
     * @inheritDoc
     */
    public function getYearlyCoverholderCommission(): float
    {
        return $this->getYearlyTotalCommission() - Helvetia::YEARLY_BROKER_COMMISSION;
    }

    /**
     * @inheritDoc
     */
    public function getYearlyBrokerCommission(): float
    {
        return Helvetia::YEARLY_BROKER_COMMISSION;
    }

    /**
     * @inheritDoc
     */
    public function getExpectedCommission(\DateTime $date = null): float
    {
        // TODO: this.
        return 0;
    }
}
