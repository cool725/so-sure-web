<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable
 */
class SoSurePayment extends Payment
{
    public static function duplicate(Payment $payment)
    {
        $sosurePayment = new SoSurePayment();
        $sosurePayment->setSuccess(true);
        $sosurePayment->setAmount($payment->getAmount());
        $sosurePayment->setTotalCommission($payment->getTotalCommission());

        return $sosurePayment;
    }
}
