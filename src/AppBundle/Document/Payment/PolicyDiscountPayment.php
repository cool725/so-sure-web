<?php

namespace AppBundle\Document\Payment;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class PolicyDiscountPayment extends Payment
{
    public function __construct()
    {
        parent::__construct();
        $this->setSuccess(true);
        $this->setSource(self::SOURCE_SYSTEM);
    }

    public function isSuccess()
    {
        return $this->success;
    }

    public function isUserPayment()
    {
        return false;
    }

    public function isDiscount()
    {
        return true;
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
        if ($this->amount < 0) {
            return "Policy Discount adjustment";
        } else {
            return "Policy Discount";
        }
    }
}
