<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable
 */
class BacsPayment extends Payment
{
    public function __construct()
    {
        $this->setSuccess(true);
        $this->setSource(self::SOURCE_BACS);
    }

    public function isSuccess()
    {
        return $this->success;
    }
}
