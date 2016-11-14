<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Classes\Salva;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PhonePolicyRepository")
 * @Gedmo\Loggable
 */
class SalvaPhonePolicy extends PhonePolicy
{
    // Policy shouldn't be set to salva
    const SALVA_STATUS_SKIPPED = 'skipped';

    // Policy needs to be sent to salva
    const SALVA_STATUS_PENDING = 'pending';

    // Policy has been accepted by salva
    const SALVA_STATUS_ACTIVE = 'active';

    // Policy has been cancelled at salva (final cancellation)
    const SALVA_STATUS_CANCELLED = 'cancelled';

    // Policy needs to cancelled, but needs refund to finish - will change to pending-cancelled after its allowed
    const SALVA_STATUS_WAIT_CANCELLED = 'wait-cancelled';

    // Policy needs to cancelled - will change to cancelled after acceptance
    const SALVA_STATUS_PENDING_CANCELLED = 'pending-cancelled';

    // Policy needs to be replaced (cancelled/created) at salva - will replacement-create to active after acceptance
    const SALVA_STATUS_PENDING_REPLACEMENT_CANCEL = 'replacement-cancel';

        // Policy needs to be replaced (cancelled/created) at salva - will change to active after acceptance
    const SALVA_STATUS_PENDING_REPLACEMENT_CREATE = 'replacement-create';

    /**
     * @Assert\Choice({"pending", "active", "cancelled", "wait-cancelled", "pending-cancelled",
     *      "replacement-cancel", "replacement-create", "skipped"})
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $salvaStatus;

    /** @MongoDB\Field(type="hash") */
    protected $salvaPolicyNumbers = array();

    /** @MongoDB\Field(type="hash") */
    protected $salvaPolicyResults = array();

    public function getSalvaStatus()
    {
        return $this->salvaStatus;
    }

    public function setSalvaStatus($salvaStatus)
    {
        $this->salvaStatus = $salvaStatus;
    }

    public function getSalvaPolicyNumbers()
    {
        return $this->salvaPolicyNumbers;
    }

    public function getSalvaPolicyResults()
    {
        return $this->salvaPolicyResults;
    }

    public function addSalvaPolicyResults($responseId, $cancel)
    {
        $key = sprintf('%d-create', $this->getLatestSalvaPolicyNumberVersion());
        if ($cancel) {
            $key = sprintf('%d-cancel', $this->getLatestSalvaPolicyNumberVersion() - 1);
        }
        $this->salvaPolicyResults[$key] = serialize(['responseId' => $responseId, 'time' => new \DateTime()]);
    }

    public function getLatestSalvaPolicyNumberVersion()
    {
        return count($this->salvaPolicyNumbers) + 1;
    }

    public function getSalvaPolicyNumberByDate(\DateTime $date)
    {
        return $this->getSalvaPolicyNumber($this->getSalvaVersion($date));
    }

    public function getSalvaPolicyNumber($version = null)
    {
        if (!$this->getPolicyNumber()) {
            return null;
        }
        if (!$version) {
            $version = $this->getLatestSalvaPolicyNumberVersion();
        }

        return sprintf("%s/%d", $this->getPolicyNumber(), $version);
    }

    public function getLatestSalvaStartDate()
    {
        return $this->getSalvaStartDate($this->getLatestSalvaPolicyNumberVersion());
    }

    public function getSalvaStartDate($version = null)
    {
        if (!$version) {
            $version = count($this->getSalvaPolicyNumbers());
        } else {
            $version = $version - 1;
        }

        if (!isset($this->getSalvaPolicyNumbers()[$version])) {
            return $this->getStart();
        }

        return new \DateTime($this->getSalvaPolicyNumbers()[$version]);
    }

    public function getSalvaTerminationDate($version = null)
    {
        if (!$version) {
            return null;
        }

        return new \DateTime($this->getSalvaPolicyNumbers()[$version]);
    }
    
    public function incrementSalvaPolicyNumber(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $version = $this->getLatestSalvaPolicyNumberVersion();
        $this->salvaPolicyNumbers[$version] = $date->format(\DateTime::ATOM);

        return $version;
    }

    public function getSalvaVersion(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $versions = $this->getSalvaPolicyNumbers();
        ksort($versions);
        foreach ($versions as $version => $versionDate) {
            if (new \DateTime($versionDate) > $date) {
                return $version;
            }
        }

        // Current version null
        return null;
    }

    public function getPaymentsForSalvaVersions($multiArray = true)
    {
        $payments = [];
        $flatPayments = [];
        $paymentsUsed = [];
        $salvaPolicyNumbers = $this->getSalvaPolicyNumbers();
        foreach ($salvaPolicyNumbers as $version => $versionDate) {
            $payments[$version] = [];
            foreach ($this->getPayments() as $payment) {
                if ($payment->isSuccess()) {
                    if ($payment->getDate() < new \DateTime($versionDate) &&
                        !in_array($payment->getId(), $paymentsUsed)) {
                        $paymentsUsed[] = $payment->getId();
                        $payments[$version][] = $payment;
                        $flatPayments[] = $payment;
                    }
                }
            }
        }

        if ($multiArray) {
            return $payments;
        } else {
            return $flatPayments;
        }
    }

    public function create($seq, $prefix = null, \DateTime $startDate = null)
    {
        parent::create($seq, $prefix, $startDate);
        if ($this->isPrefixInvalidPolicy()) {
            $this->setSalvaStatus(self::SALVA_STATUS_SKIPPED);
        } else {
            $this->setSalvaStatus(self::SALVA_STATUS_PENDING);
        }
    }

    public function cancel($reason, \DateTime $date = null)
    {
        parent::cancel($reason, $date);

        // Assume a wait state - the refund listener will determine if it can progress to pending
        // based on if refund is required or not
        $this->setSalvaStatus(self::SALVA_STATUS_WAIT_CANCELLED);
    }

    public function hasSalvaPreviousVersionPastMidnight($version = null)
    {
        if (!$this->isPolicy() || !$this->getSalvaStartDate($version)) {
            throw new \Exception('Unable to determine days in salva policy as policy is not valid');
        }

        if (!isset($this->getSalvaPolicyNumbers()[$version + 1])) {
            $currentStartDate = $this->getSalvaStartDate($version + 1);
        } else {
            $currentStartDate = $this->getSalvaStartDate(null);
        }
        $previousStartDate = $this->getSalvaStartDate($version);

        /*
        print PHP_EOL . $version . PHP_EOL;
        print $previousStartDate->format(\DateTime::ATOM) . PHP_EOL;
        print $currentStartDate->format(\DateTime::ATOM) . PHP_EOL;
        */

        // at least 1 full day, always
        if ($previousStartDate->diff($currentStartDate)->days > 0) {
            return true;
        }

        // Otherwise, for less than 1 day, has the midnight boundary been passed
        $midnight = $this->endOfDay(clone $previousStartDate);
        if ($currentStartDate >= $midnight) {
            return true;
        }

        return false;
    }

    public function getSalvaDaysInPolicy($version = null)
    {
        if (!$this->isPolicy() || !$this->getSalvaStartDate($version)) {
            throw new \Exception('Unable to determine days in salva policy as policy is not valid');
        }

        $startDate = $this->getSalvaStartDate($version);
        $startDate = $this->startOfDay($startDate);
        $endDate = null;
        if ($version) {
            // if we're versioned, the previous period was already billed for the start date
            // if that's the case, then adjust the start date to beginning of next day only for prorate date calcs
            if ($version > 1) {
                $startDate = $this->endOfDay($startDate);
            }

            $endDate = $this->getSalvaTerminationDate($version);
        } elseif ($this->getStatus() == SalvaPhonePolicy::STATUS_CANCELLED) {
            if ($this->isRefundAllowed()) {
                $endDate = clone $this->getEnd();
            } elseif ($this->getPremiumPlan() == self::PLAN_MONTHLY) {
                $endDate = $this->getNextBillingDate(clone $this->getEnd());
            }
        }

        if (!$endDate) {
            $endDate = clone $this->getStaticEnd();
        }

        // special case to count first day, if versioned on first day
        if ($this->getStart()->diff($endDate)->days == 0 && $version == 1) {
            return 1;
        }

        // same day versioning
        if ($endDate < $startDate) {
            return 0;
        }

        // due to adjusting the end date to beginning of next day below,
        // TODO: Refactor
        if (!$version && count($this->getSalvaPolicyNumbers()) > 0) {
            $endDate = $endDate->sub(new \DateInterval('P1D'));
        }

        // always bill to end of day (next day 00:00) to account for partial days
        $endDate = $this->endOfDay($endDate);

        $diff = $startDate->diff($endDate);
        $days = $diff->days;

        /*
        print PHP_EOL . $version . '=> ' . $days . PHP_EOL;
        print $startDate->format(\DateTime::ATOM) . PHP_EOL;
        print $endDate->format(\DateTime::ATOM) . PHP_EOL;
        print_r($diff);
        */

        return $days;
    }

    public function getSalvaProrataMultiplier($version = null)
    {
        // TODO: in the case of policies that don't have a refund, the max version should continue to end of year
        return $this->getSalvaDaysInPolicy($version) / $this->getDaysInPolicyYear();
    }

    public function getTotalPremiumPrice($version = null)
    {
        // Cooloff is always 0 (if allowed)
        if ($this->isRefundAllowed() && $this->isCooloffCancelled()) {
            return 0;
        }

        $totalPremium = $this->getPremium()->getYearlyPremiumPrice() * $this->getSalvaProrataMultiplier($version);

        return $this->toTwoDp($totalPremium);
    }

    public function getUsedGwp($version = null, $isReplacement = false)
    {
        // expected to send if no issues with payments
        $totalGwp = $this->getTotalGwp($version);

        if ($isReplacement) {
            return $this->toTwoDp($totalGwp);
        }

        // get history on all previously sent used gwps
        $previousGwp = 0;
        $salvaPolicyNumbers = $this->getSalvaPolicyNumbers();
        foreach ($salvaPolicyNumbers as $version => $versionDate) {
            $previousGwp += $this->getTotalGwpActual($version);
        }
        // print sprintf('%f %f %f', $totalGwp, $previousGwp, $this->getGwpPaid());

        if ($totalGwp + $previousGwp > $this->getGwpPaid()) {
            // We should never exceed what we've been paid
            return $this->toTwoDp($this->getGwpPaid() - $previousGwp);
        } elseif ($this->isRefundAllowed() && $this->isCooloffCancelled()) {
            // For cooloff, just in case, we've reported gwp for a salva policy update, use a neg offset of what we
            // previously sent
            return $this->toTwoDp(0 - $previousGwp);
        }

        return $this->toTwoDp($totalGwp);
    }

    public function getTotalGwp($version = null)
    {
        // Cooloff is always 0 (if allowed)
        if ($this->isRefundAllowed() && $this->isCooloffCancelled()) {
            return 0;
        }

        return $this->getTotalGwpActual($version);
    }

    private function getTotalGwpActual($version = null)
    {
        $totalGwp = $this->toTwoDp(
            $this->getPremium()->getYearlyGwpActual() * $this->getSalvaProrataMultiplier($version)
        );

        return $this->toTwoDp($totalGwp);
    }

    public function getTotalIpt($version = null)
    {
        // Cooloff is always 0 (if allowed)
        if ($this->isRefundAllowed() && $this->isCooloffCancelled()) {
            return 0;
        }

        $totalIpt = $this->getPremium()->getYearlyIptActual() * $this->getSalvaProrataMultiplier($version);

        return $this->toTwoDp($totalIpt);
    }

    public function getTotalBrokerFee($version = null)
    {
        // Cooloff is always 0 (if allowed)
        if ($this->isRefundAllowed() && $this->isCooloffCancelled()) {
            return 0;
        }

        $totalBrokerFee = Salva::YEARLY_TOTAL_COMMISSION * $this->getSalvaProrataMultiplier($version);

        return $this->toTwoDp($totalBrokerFee);
    }

    public function getSalvaConnections($version)
    {
        if ($this->isCancelled() || $version) {
            return 0;
        }

        return count($this->getConnections());
    }

    public function getSalvaPotValue($version)
    {
        if ($this->isCancelled() || $version) {
            return 0;
        }

        return $this->getPotValue();
    }

    public function getSalvaPromoPotValue($version)
    {
        if ($this->isCancelled() || $version) {
            return 0;
        }

        return $this->getPromoPotValue();
    }
}
