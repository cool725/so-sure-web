<?php

namespace AppBundle\Document\Payment;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\BacsPaymentRepository")
 * @Gedmo\Loggable
 */
class BacsPayment extends Payment
{
    use DateTrait;

    const DAYS_CREDIT = 2;
    const DAYS_REVERSE = 5;

    const STATUS_PENDING = 'pending';
    const STATUS_GENERATED = 'generated';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILURE = 'failure';

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $manual;

    /**
     * @Assert\Choice({
     *      "pending",
     *      "generated",
     *      "submitted",
     *      "transfer-wait-exception",
     *      "success",
     *      "failure"
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

    public function isManual()
    {
        return $this->manual;
    }

    public function setManual($manual)
    {
        $this->manual = $manual;
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

    public function submit(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        $this->setDate($date);
        $this->setSubmittedDate($date);
        $this->setBacsCreditDate($this->addBusinessDays($date, self::DAYS_CREDIT));
        $this->setBacsReversedDate($this->addBusinessDays($date, self::DAYS_REVERSE));
    }

    public function inProgress()
    {
        if (in_array($this->getStatus(), [self::STATUS_SUCCESS, self::STATUS_FAILURE])) {
            return false;
        }

        return true;
    }

    public function canAction(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        // already completed
        if (!$this->inProgress()) {
            return false;
        }

        $reversedDate = $this->startOfDay($this->getBacsReversedDate());
        $diff = $reversedDate->diff($this->startOfDay($date));
        if ($diff->d == 0 || (!$diff->invert && $diff->d > 0)) {
            return true;
        } else {
            return false;
        }
    }

    public function approve(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        if (!$this->canAction($date)) {
            throw new \Exception(sprintf(
                'Attempting to action before reveral date (%s) is past',
                $this->getBacsReversedDate()->format('d m Y')
            ));
        }

        $this->setStatus(self::STATUS_SUCCESS);
        $this->setSuccess(true);

        $this->setCommission();

        /** @var BacsPaymentMethod $bacsPaymentMethod */
        $bacsPaymentMethod = $this->getPolicy()->getUser()->getPaymentMethod();
        $bacsPaymentMethod->getBankAccount()->setLastSuccessfulPaymentDate($date);
    }

    public function reject(\DateTime $date = null)
    {
        if (!$this->canAction($date)) {
            throw new \Exception(sprintf(
                'Attempting to action before reveral date (%s) is past',
                $this->getBacsReversedDate()->format('d m Y')
            ));
        }

        $this->setStatus(self::STATUS_FAILURE);
        $this->setSuccess(false);
    }

    public function isSuccess()
    {
        return $this->success;
    }

    public function isUserPayment()
    {
        return true;
    }
}
