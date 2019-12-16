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
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\HelvetiaPhonePolicyRepository")
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
            $coverholderCommission = ($this->getPremium()->getMonthlyPremiumPrice() *
                Helvetia::COVERHOLDER_COMMISSION_PROPORTION - Helvetia::MONTHLY_BROKER_COMMISSION) * $n;
            $payment->setCommission($coverholderCommission, Helvetia::MONTHLY_BROKER_COMMISSION * $n);
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

    /**
     * Gives you the proportionate amount of premium owed given the end date.
     * @return float the amount of premium due on this policy.
     */
    public function getProRataPremium()
    {
        if ($this->isRefundAllowed() && $this->isCooloffCancelled()) {
            return 0;
        }
        return $this->getPremium()->getYearlyPremiumPrice() * $this->proRataMultiplier();
    }

    /**
     * Gives you the proportionate amount of ipt owed given the end date.
     * @return float the amount of ipt due on this policy.
     */
    public function getProRataIpt()
    {
        if ($this->isRefundAllowed() && $this->isCooloffCancelled()) {
            return 0;
        }
        return $this->getPremium()->getYearlyIptActual() * $this->proRataMultiplier();
    }

    /**
     * Gives you the proportionat4e amount of broker fee owed given the policy start and end.
     * @return float the amount of broker fee due on this policy.
     */
    public function getProRataBrokerFee()
    {
        if ($this->isRefundAllowed() && $this->isCooloffCancelled()) {
            return 0;
        }
        return Helvetia::YEARLY_BROKER_COMMISSION * $this->proRataMultiplier();
    }

    /**
     * Gives you a number by which you can multiply a yearly value to give a value proportional to the amount of the
     * policy that actually got run.
     * @return float the multiplier.
     */
    public function proRataMultiplier()
    {
        $actualDays = $this->getStart()->diff($this->getEnd())->days;
        $fullDays = $this->policyDays();
        return $actualDays / $fullDays;
    }

    /**
     * Gives you the number of days in the full policy from start date to the final end date.
     * @return int the number of days from the policy start to the policy expiration date.
     */
    public function policyDays()
    {
        return $this->getStart()->diff($this->getStaticEnd())->days;
    }
}
