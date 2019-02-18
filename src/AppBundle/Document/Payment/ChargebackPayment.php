<?php

namespace AppBundle\Document\Payment;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ChargebackPaymentRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class ChargebackPayment extends Payment
{
    public function __construct()
    {
        parent::__construct();
        $this->setSuccess(true);
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

        return 'Chargeback';
    }

    /**
     * Specific logic for whether to show a payment to users.
     * @inheritDoc
     */
    public function isVisibleUserPayment()
    {
        if ($this->areEqualToTwoDp(0, $this->amount)) {
            return false;
        }

        return $this->success;
    }

    /**
     * Gives the name that this payment should be called by to users when there is not an overriding circumstance.
     * @inheritDoc
     */
    protected function userPaymentName()
    {
        return "Refund due to card dispute";
    }
}
