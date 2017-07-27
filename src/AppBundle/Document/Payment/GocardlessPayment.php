<?php

namespace AppBundle\Document\Payment;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable
 */
class GocardlessPayment extends Payment
{
    public function isSuccess()
    {
        return true;
    }
}
