<?php

namespace AppBundle\Document;

use AppBundle\Document\Policy\Policy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Classes\Helvetia;
use AppBundle\Classes\NoOp;
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
     * The previous iterations of this policy.
     * @MongoDB\EmbedMany(targetDocument="AppBundle\Document\PhonePolicyIteration")
     */
    protected $previousIterations = [];

    /**
     * Gives you the previous iterations that this policy has has.
     * @return array containing the previous iterations.
     */
    public function getPreviousIterations()
    {
        if (is_array($this->previousIterations)) {
            return $this->previousIterations;
        }
        return $this->previousIterations->toArray();
    }

    /**
     * Add a previous iteration to the list of previous iterations.
     * @param PhonePolicyIteration $previousIteration is the iteration of the policy to add.
     */
    public function addPreviousIteration($previousIteration)
    {
        $this->previousIterations[] = $previousIteration;
    }

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
        NoOp::ignore([$allowFraction, $date]);
        $payment->setCoverholderCommission($payment->getGwp() * Helvetia::COMMISSION_PROPORTION);
        $n = $this->getPremium()->getNumberOfMonthlyPayments($payment->getAmount());
        $payment->setBrokerCommission(Helvetia::MONTHLY_BROKER_COMMISSION * $n);
        $payment->setTotalCommission($payment->getCoverholderCommission() + $payment->getBrokerCommission());
    }

    /**
     * @inheritDoc
     */
    public function getYearlyTotalCommission(): float
    {
        $premium = $this->getPremium();
        if (!$premium) {
            return 0;
        }
        return $premium->getGwp() * 12 * Helvetia::COMMISSION_PROPORTION + Helvetia::YEARLY_BROKER_COMMISSION;
    }

    /**
     * @inheritDoc
     */
    public function getYearlyCoverholderCommission(): float
    {
        $premium = $this->getPremium();
        if (!$premium) {
            return 0;
        }
        return $premium->getGwp() * 12 * Helvetia::COMMISSION_PROPORTION;
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
        $expectedCommission = null;
        $totalPayments = $this->getTotalSuccessfulStandardPayments(false, $date);
        $expectedPayments = $this->getTotalExpectedPaidToDate($date);
        $isMoneyOwed = !$this->areEqualToTwoDp($totalPayments, $expectedPayments) && $totalPayments < $expectedPayments;
        $numPayments = $this->getPremium()->getNumberOfMonthlyPayments($totalPayments);
        if ($numPayments > 12 || $numPayments < 0) {
            throw new \Exception(sprintf('Unable to calculate expected broker fees for policy %s', $this->getId()));
        }
        $expectedMonthlyCommission = $numPayments *
            ($this->getPremium()->getGwp() * Helvetia::COMMISSION_PROPORTION + Helvetia::MONTHLY_BROKER_COMMISSION);
        $commissionReceived = Payment::sumPayments($this->getSuccessfulPayments(), true)['totalCommission'];
        if ($this->isCooloffCancelled()) {
            return 0;
        } elseif (in_array($this->getStatus(), [
            self::STATUS_ACTIVE,
            self::STATUS_UNPAID,
            self::STATUS_PICSURE_REQUIRED
        ])) {
            $expectedCommission = $expectedMonthlyCommission;
        } elseif ($this->isCancelled() && (!$this->isRefundAllowed() || $isMoneyOwed)) {
            if ($numPayments) {
                $expectedCommission = $expectedMonthlyCommission;
            } else {
                $expectedCommission = $commissionReceived;
            }
        } elseif (in_array($this->getStatus(), [
            self::STATUS_EXPIRED,
            self::STATUS_EXPIRED_CLAIMABLE,
            self::STATUS_EXPIRED_WAIT_CLAIM]) && $numPayments == 11) {
            $expectedCommission = $expectedMonthlyCommission;
        } else {
            if (!$date) {
                $date = \DateTime::createFromFormat('U', time());
            }
            if ($date > $this->getEnd()) {
                $date = $this->getEnd();
            }
            $expectedCommission = $this->getProratedCommission($date);
        }
        return $expectedCommission;
    }

    /**
     * @inheritDoc
     */
    public function getTotalExpectedPaidToDate(\DateTime $date = null, $firstDayIsUnpaid = false)
    {
        if (!$this->isPolicy() || !$this->getStart()) {
            return null;
        }
        if ($this->getPremiumPlan() == self::PLAN_YEARLY) {
            return $this->getYearlyPremiumPrice();
        } else {
            $date = DateTrait::adjustDayForBilling($date ?: new \DateTime(), true);
            $futurePayments = count($this->getInvoiceSchedule($date));
            if (DateTrait::startOfDay($date) == DateTrait::startOfDay($this->getStart())) {
                $futurePayments++;
            }
            $upgradePrice = $this->getUpgradedStandardMonthlyPrice();
            return $this->getYearlyPremiumPrice() - $futurePayments * $upgradePrice;
        }

    }

    /**
     * Says how much this policy would pay per month for the rest of the policy if upgraded onto the given premium.
     * @param PhonePremium $premium is the premium.
     * @param \DateTime    $date    is the day of upgrade.
     * @return float the amount they will pay per month.
     */
    public function getPremiumUpgradeCostMonthly($premium, \DateTime $date = null)
    {
        $date = $date ?: new \DateTime();
        $remainingPayments = count($this->getInvoiceSchedule($date)) - $this->countPendingScheduledPayments();
        if ($remainingPayments == 0) {
            return 0;
        }
        $outstanding = 0 - $this->getPremiumPaid();
        foreach ($this->getPreviousIterations() as $iteration) {
            $outstanding += $iteration->getProRataPremium($this->getDaysInPolicyYear());
        }
        $current = $this->getCurrentIteration();
        $future = clone $current;
        $current->setEnd(DateTrait::startOfDay($date));
        $future->setStart(DateTrait::startOfDay($date));
        $future->setEnd($this->getEnd());
        $future->setPremium($premium);
        $outstanding += $current->getProRataPremium($this->getDaysInPolicyYear());
        $outstanding += $future->getProRataPremium($this->getDaysInPolicyYear());
        return CurrencyTrait::toTwoDp($outstanding / $remainingPayments);
    }

    /**
     * Gives you the different price that the user must pay in the final month of their policy if they upgrade to the
     * given premium at the given date.
     * @param PhonePremium   $premium is the premium they are upgrading to.
     * @param \DateTime|null $date    is the date at which they are upgrading or null for right now.
     * @return float|null the different price or null if there is no difference in price.
     */
    public function getUpgradeFinalMonthDifference($premium, \DateTime $date = null)
    {
        $date = $date ?: new \DateTime();
        $remainingPayments = count($this->getInvoiceSchedule($date)) - $this->countPendingScheduledPayments();
        if ($remainingPayments == 0) {
            return 0;
        }
        $outstanding = 0 - $this->getPremiumPaid();
        foreach ($this->getPreviousIterations() as $iteration) {
            $outstanding += $iteration->getProRataPremium($this->getDaysInPolicyYear());
        }
        $current = $this->getCurrentIteration();
        $future = clone $current;
        $current->setEnd(DateTrait::startOfDay($date));
        $future->setStart(DateTrait::startOfDay($date));
        $future->setEnd($this->getEnd());
        $future->setPremium($premium);
        $outstanding += $current->getProRataPremium($this->getDaysInPolicyYear());
        $outstanding += $future->getProRataPremium($this->getDaysInPolicyYear());
        $normalPrice = $this->getPremiumUpgradeCostMonthly($premium, $date);
        $delta = $outstanding - $normalPrice * $remainingPayments;
        if ($delta == 0) {
            return null;
        }
        return CurrencyTrait::toTwoDp($normalPrice + $delta);
    }

    /**
     * Tells you how much this policy would pay in a lump if upgraded onto the given premium.
     * @param PhonePremium $premium is the premium.
     * @param \DateTime    $date    is the day of upgrade.
     * @return float the amount they will pay per month.
     */
    public function getPremiumUpgradeCostYearly($premium, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $outstanding = 0;
        foreach ($this->getPreviousIterations() as $iteration) {
            $outstanding += $iteration->getProRataPremium($this->getDaysInPolicyYear());
        }
        $current = $this->getCurrentIteration();
        $future = clone $current;
        $current->setEnd(DateTrait::startOfDay($date));
        $future->setStart(DateTrait::startOfDay($date));
        $future->setEnd($this->getEnd());
        $future->setPremium($premium);
        $outstanding += $current->getProRataPremium($this->getDaysInPolicyYear());
        $outstanding += $future->getProRataPremium($this->getDaysInPolicyYear());
        return CurrencyTrait::toTwoDp($outstanding - $this->getPremiumPaid());
    }

    /**
     * @inheritDoc
     */
    public function getUpgradedStandardMonthlyPrice()
    {
        if (!$this->getPreviousIterations()) {
            return $this->getPremium()->getAdjustedStandardMonthlyPremiumPrice();
        }
        $iterations = $this->getAllIterations();
        if (count($iterations) == 1) {
            return $this->getPremium()->getAdjustedStandardMonthlyPremiumPrice();
        }
        $remainingPayments = $this->countFutureInvoiceSchedule($this->getCurrentIterationStart());
        if ($remainingPayments == 0) {
            return 0;
        }
        $outstanding = 0;
        foreach ($iterations as $iteration) {
            $outstanding += $iteration->getProRataPremium($this->getDaysInPolicyYear());
        }
        return CurrencyTrait::toTwoDp(
            ($outstanding - $this->getPremiumPaidPrior($this->getCurrentIterationStart())) / $remainingPayments
        );
    }

    /**
     * @inheritDoc
     */
    public function getUpgradedFinalMonthlyPrice()
    {
        if (!$this->getPreviousIterations()) {
            return $this->getPremium()->getAdjustedFinalMonthlyPremiumPrice();
        }
        $iterations = $this->getAllIterations();
        if (count($iterations) == 1) {
            return $this->getPremium()->getAdjustedFinalMonthlyPremiumPrice();
        }
        $remainingPayments = $this->countFutureInvoiceSchedule($this->getCurrentIterationStart());
        if ($remainingPayments == 0) {
            return 0;
        }
        $price = $this->getUpgradedStandardMonthlyPrice();
        $overall = $this->getUpgradedYearlyPrice();
        $delta = $overall - $price * $remainingPayments;
        return CurrencyTrait::toTwoDp($price + $delta);
    }

    /**
     * @inheritDoc
     */
    public function getUpgradedYearlyPrice()
    {
        if (!$this->getPreviousIterations()) {
            return $this->getPremium()->getAdjustedYearlyPremiumPrice();
        }
        $outstanding = 0;
        foreach ($this->getAllIterations() as $iteration) {
            $outstanding += $iteration->getProRataPremium($this->getDaysInPolicyYear());
        }
        return CurrencyTrait::toTwoDp($outstanding - $this->getPremiumPaidPrior($this->getCurrentIterationStart()));
    }

    /**
     * @inheritDoc
     */
    public function getYearlyPremiumPrice()
    {
        if (!$this->getPreviousIterations()) {
            return $this->getPremium()->getAdjustedYearlyPremiumPrice();
        }
        return array_reduce($this->getAllIterations(), function ($carry, $iteration) {
            return $carry + $iteration->getProRataPremium($this->getDaysInPolicyYear());
        }, 0);
    }

    /**
     * @inheritDoc
     */
    public function getYearlyIpt()
    {
        $iterations = $this->getAllIterations();
        if (count($iterations) == 1) {
            return $this->getPremium()->getYearlyIpt();
        }
        return array_reduce($iterations, function ($carry, $iteration) {
            return $carry + $iteration->getProRataIpt($this->getDaysInPolicyYear());
        }, 0);
    }

    /**
     * @inheritDoc
     */
    public function isUpgraded()
    {
        foreach ($this->getPreviousIterations() as $previousIteration) {
            return true;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getCurrentIteration()
    {
        $current = parent::getCurrentIteration();
        $current->setStart(array_reduce($this->getPreviousIterations(), function ($carry, $iteration) {
            $date = $iteration->getEnd();
            if ($date > $carry) {
                return $date;
            }
            return $carry;
        }, DateTrait::startOfDay($this->getStart())));
        return $current;
    }

    /**
     * @inheritDoc
     */
    public function getCurrentIterationStart()
    {
        return array_reduce($this->getPreviousIterations(), function ($carry, $iteration) {
            $date = $iteration->getRealEnd();
            return ($date > $carry) ? $date : $carry;
        }, $this->getStart());
    }

    /**
     * Gives you all of the iterations of the policy including the current status of the policy continuing to the
     * static end of the policy as an iteration.
     * @return array containing the iterations in ascending order.
     */
    public function getAllIterations()
    {
        $iterations = $this->getPreviousIterations();
        $iterations[] = $this->getCurrentIteration();
        return $iterations;
    }

    /**
     * Gives you the amount of money helvetia should have gotten out of this policy currently.
     * @return float the amount of cash.
     */
    public function getHelvetiaCash()
    {
        return $this->getPremiumPaid() - $this->getCoverholderCommissionPaid() - $this->getBrokerCommissionPaid();

    }

    /**
     * Gives you all of the iterations of the policy including the current status of the policy continuing to the
     * non-static end of the policy as an iteration.
     * @return array containing the iterations in ascending order.
     */
    public function getAllIterationsShort()
    {
        $iterations = $this->getPreviousIterations();
        $current = $this->getCurrentIteration();
        $current->setEnd($this->getEnd());
        $iterations[] = $current;
        return $iterations;
    }

    /**
     * @inheritDoc
     */
    public function getProratedPremium(\DateTime $date = null)
    {
        NoOp::ignore($date);
        return array_reduce($this->getAllIterationsShort(), function ($carry, $iteration) {
            return $carry + $iteration->getProRataPremium($this->getDaysInPolicyYear());
        }, 0);
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
        return array_reduce($this->getAllIterationsShort(), function ($carry, $iteration) {
            return $carry + $iteration->getProRataPremium($this->getDaysInPolicyYear());
        }, 0);
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
     * Gives you the proportionate amount of broker fee owed given the policy start and end.
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
