<?php

namespace AppBundle\Document\Payment;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable
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
}
