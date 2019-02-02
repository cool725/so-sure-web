<?php

namespace AppBundle\Document\Payment;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class SoSurePayment extends Payment
{
    public static function init($source)
    {
        $sosurePayment = new SoSurePayment();
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
    public function getUserPaymentDisplay()
    {
        return "Card Payment";
    }
}
