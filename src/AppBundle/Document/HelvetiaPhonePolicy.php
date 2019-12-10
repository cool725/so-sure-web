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
    public function setCommission($payment, $allowFraction = false, \DateTime $date = null)
    {
        $date = $date ?: new \DateTime();
        if ($this->getPremium()->isEvenlyDivisible($payment->getAmount())) {
            $n = $this->getPremium()->getNumberOfMonthlyPayments($payment->getAmount());
            $payment->setCommission(
                $this->getPremium()->getMonthlyPremiumPrice() * Helvetia::COVERHOLDER_COMMISSION_PROPORTION * $n,
                Helvetia::MONTHLY_BROKER_COMMISSION * $n
            );
        } elseif ($allowFraction) {
            if ($payment->getAmount() >= 0) {
                $payment->setCommission(
                    $this->getProratedCoverholderCommissionPayment($date),
                    $this->getProratedBrokerCommissionPayment($date)
                );
            } else {
                $payment->setCommission(
                    $this->getProratedCoverholderCommissionRefund($date),
                    $this->getProratedBrokerCommissionRefund($date)
                );
            }
        }
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
        return $premium->getYearlyPremiumPrice() * Helvetia::COVERHOLDER_COMMISSION_PROPORTION;
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
        // TODO: this. Just needs to step it by a fixed amount for each month so should be easy.
        return 0;
    }
}
