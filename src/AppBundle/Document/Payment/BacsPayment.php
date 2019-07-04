<?php

namespace AppBundle\Document\Payment;

use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\BacsPaymentRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class BacsPayment extends Payment
{
    use DateTrait;

    const DAYS_PROCESSING = 1;
    const DAYS_CREDIT = 2;
    const DAYS_REVERSE = 5;
    const DAYS_REPRESENTING = 30;

    const STATUS_PENDING = 'pending';
    const STATUS_GENERATED = 'generated';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILURE = 'failure';
    const STATUS_SKIPPED = 'skipped';

    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';

    /**
     * A bacs payment outside the system (e.g. manually sent by the customer)
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $manual;

    /**
     * A user can advise us to take payment immediately as a one-off payment, thereby skipping any date checks
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $oneOffPayment;

    /**
     * @Assert\Choice({
     *      "pending",
     *      "generated",
     *      "submitted",
     *      "transfer-wait-exception",
     *      "success",
     *      "failure",
     *      "skipped"
     * }, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="10")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @var string
     */
    protected $serialNumber;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $submittedDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $bacsCreditDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $bacsReversedDate;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Payment\BacsPayment", inversedBy="reverses")
     * @Gedmo\Versioned
     * @var BacsPayment
     */
    protected $reversedBy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Payment\BacsPayment", inversedBy="reversedBy")
     * @Gedmo\Versioned
     * @var BacsPayment
     */
    protected $reverses;

    public function isManual()
    {
        return $this->manual;
    }

    public function setManual($manual)
    {
        $this->manual = $manual;
    }

    public function isOneOffPayment()
    {
        return $this->oneOffPayment;
    }

    public function setIsOneOffPayment($oneOffPayment)
    {
        $this->oneOffPayment = $oneOffPayment;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = $serialNumber;
    }

    public function getSubmittedDate()
    {
        return $this->submittedDate;
    }

    public function setSubmittedDate(\DateTime $submittedDate)
    {
        $this->submittedDate = $submittedDate;
    }

    public function getBacsCreditDate()
    {
        return $this->bacsCreditDate;
    }

    public function setBacsCreditDate(\DateTime $bacsCreditDate)
    {
        $this->bacsCreditDate = $bacsCreditDate;
    }

    public function getBacsReversedDate()
    {
        return $this->bacsReversedDate;
    }

    public function setBacsReversedDate(\DateTime $bacsReversedDate)
    {
        $this->bacsReversedDate = $bacsReversedDate;
    }

    public function getReversedBy()
    {
        return $this->reversedBy;
    }

    public function setReversedBy(BacsPayment $reversedBy)
    {
        $this->reversedBy = $reversedBy;
    }

    public function getReverses()
    {
        return $this->reverses;
    }

    public function setReverses(BacsPayment $reverses)
    {
        $this->reverses = $reverses;
    }

    /**
     * Sets this payment as reversed by another payment, setting the correct properties on both.
     * @param BacsPayment $reverse is the payment to set as reversing this one.
     */
    public function addReverse($reverse)
    {
        $this->setReversedBy($reverse);
        $reverse->setReverses($this);
    }

    public function submit(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        // if payment has already been scheduled for submission in the future (e.g. scheduled payment a few days in
        // advance), then base off of that date instead
        if ($this->getSubmittedDate() > $date) {
            $date = $this->getSubmittedDate();
        }

        $this->setDate($date);
        $this->setSubmittedDate($date);
        $this->setBacsCreditDate($this->addBusinessDays($date, self::DAYS_CREDIT));
        $this->setBacsReversedDate($this->addBusinessDays($date, self::DAYS_REVERSE));
        $this->setPolicyStatusActiveIfUnpaidAndPaidToDate();
    }

    /**
     * If this payment has a policy with status unpaid and that policy is paid to date, then it is set to active.
     */
    public function setPolicyStatusActiveIfUnpaidAndPaidToDate()
    {
        if ($this->getPolicy() && $this->getPolicy()->isPolicyPaidToDate(null, true)) {
            $this->getPolicy()->setPolicyStatusActiveIfUnpaid();
        }
    }

    public function inProgress()
    {
        if (in_array($this->getStatus(), [self::STATUS_SUCCESS, self::STATUS_FAILURE])) {
            return false;
        }

        return true;
    }

    public function canAction($type, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        // already completed
        if (!$this->inProgress()) {
            return false;
        }

        if ($this->inProgress() && !$this->getBacsReversedDate()) {
            return false;
        }

        // reject can occur at any time
        if ($type == self::ACTION_REJECT) {
            return true;
        }

        // accept should be on or after reversal date
        $reversedDate = $this->startOfDay($this->getBacsReversedDate());
        $diff = $reversedDate->diff($this->startOfDay($date));
        if ($diff->d == 0 || (!$diff->invert && $diff->d > 0)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Sets the bacs payment as having been approved.
     * @param \DateTime $date               is the date that the payment was approved at.
     * @param boolean   $ignoreReversedDate whether to make sure payment is not approved while it could still be undone.
     * @param boolean   $setCommission      whether to set the commission. If setting it has already been attempted then
     *                                      there is not any point doing it again.
     */
    public function approve(\DateTime $date = null, $ignoreReversedDate = false, $setCommission = true)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!$this->canAction(self::ACTION_APPROVE, $date) && !$ignoreReversedDate) {
            throw new \Exception(sprintf(
                'Attempting to approve payment %s before reversal date (%s) is past. policy: %s',
                $this->getId(),
                $this->getBacsReversedDate()->format('d m Y'),
                $this->getPolicy()->getPolicyNumber()
            ));
        }

        $this->setDate($date);
        $this->setStatus(self::STATUS_SUCCESS);
        $this->setSuccess(true);

        // Usually commission would not be set, however, if we may have needed to manully set the commission
        if ($setCommission && !$this->getTotalCommission()) {
            $this->setCommission(true);
        }

        if ($this->getPolicy()->hasPolicyOrUserBacsPaymentMethod()) {
            /** @var BacsPaymentMethod $bacsPaymentMethod */
            $bacsPaymentMethod = $this->getPolicy()->getPolicyOrUserBacsPaymentMethod();
            $bacsPaymentMethod->getBankAccount()->setLastSuccessfulPaymentDate($date);
        }

        if ($this->getScheduledPayment()) {
            $this->getScheduledPayment()->setStatus(ScheduledPayment::STATUS_SUCCESS);
        }

        // if the policy is owning this payment no longer active then we have to refund.
        $policy = $this->getPolicy();
        if ($policy && $policy->isCancelled()) {
            $refund = $policy->getRefundAmount(false, false, true);
            if ($refund > 0) {
                $scheduledPayment = new ScheduledPayment();
                $scheduledPayment->setAmount(0 - $refund);
                $scheduledPayment->setPolicy($policy);
                $scheduledPayment->setNotes('refund for bacs payment after cancellation');
                $scheduledPayment->setScheduled(new \DateTime());
                $scheduledPayment->setType(ScheduledPayment::TYPE_REFUND);
                $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
                $policy->addScheduledPayment($scheduledPayment);
            }
        }

        $this->setPolicyStatusActiveIfUnpaidAndPaidToDate();
    }

    public function reject(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!$this->canAction(self::ACTION_REJECT, $date)) {
            throw new \Exception(sprintf(
                'Attempting to reject payment %s before reversal date (%s) is past. policy: %s',
                $this->getId(),
                $this->getBacsReversedDate()->format('d m Y'),
                $this->getPolicy()->getPolicyNumber()
            ));
        }

        $this->setDate($date);
        $this->setStatus(self::STATUS_FAILURE);
        $this->setSuccess(false);

        if ($this->getScheduledPayment()) {
            $this->getScheduledPayment()->setStatus(ScheduledPayment::STATUS_FAILED);
        }
    }

    public function isSuccess()
    {
        return $this->success;
    }

    public function isUserPayment()
    {
        return true;
    }

    /**
     * Specific logic for whether or not a bacs payment should be shown to users.
     * @inheritDoc
     */
    public function isVisibleUserPayment()
    {
        if ($this->areEqualToTwoDp(0, $this->amount)) {
            return false;
        }

        if ($this->reverses) {
            return false;
        }

        return true;
    }

    /**
     * Gives the name that this payment should be called by to users when there is not an overriding circumstance.
     * @inheritDoc
     */
    protected function userPaymentName()
    {
        if ($this->amount < 0) {
            return "Direct Debit Refund";
        } else {
            return "Direct Debit";
        }
    }
}
