<?php

namespace AppBundle\Document\Payment;

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
    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $manual;

    public function isManual()
    {
        return $this->manual;
    }

    public function setManual($manual)
    {
        $this->manual = $manual;
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
