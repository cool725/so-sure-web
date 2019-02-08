<?php

namespace AppBundle\Document\Payment;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\BacsIndemnityPaymentRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class BacsIndemnityPayment extends Payment
{
    use DateTrait;

    const STATUS_RAISED = 'raised';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_REJECTED = 'rejected';

    /**
     * @Assert\Choice({
     *      "raised",
     *      "refunded",
     *      "rejected",
     * }, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function isSuccess()
    {
        return $this->success;
    }

    public function isUserPayment()
    {
        return true;
    }

    public function getNotes()
    {
        if (parent::getNotes()) {
            return parent::getNotes();
        }

        return 'Bacs Indemnity (Chargeback)';
    }

    /**
     * Gives the name that this payment should be called by to users when there is not an overriding circumstance.
     * @inheritDoc
     */
    protected function userPaymentName()
    {
        return "Refund due to bank dispute";
    }
}
