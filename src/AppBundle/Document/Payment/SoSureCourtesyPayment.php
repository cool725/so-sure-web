<?php

namespace AppBundle\Document\Payment;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class SoSureCourtesyPayment extends SoSurePayment
{
    public static function init($source)
    {
        $sosurePayment = new SoSureCourtesyPayment();
        $sosurePayment->setSuccess(true);
        $sosurePayment->setSource($source);

        return $sosurePayment;
    }

    public function isSuccess()
    {
        return $this->success;
    }

    public function isUserPayment()
    {
        return false;
    }

    /**
     * Gives user facing description of sosure payment.
     * @inheritDoc
     */
    protected function userPaymentName()
    {
        if ($this->amount < 0) {
            return "so-sure adjustment";
        } else {
            return "Payment by so-sure";
        }
    }
}
