<?php

namespace AppBundle\Document\Opt;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Document\PhoneTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document()
 */
class SmsOptIn extends Opt
{
    use PhoneTrait;

    /**
     * @AppAssert\Mobile()
     * @MongoDB\Field(type="string", nullable=false)
     */
    protected $mobile;

    public function getMobile()
    {
        return $this->mobile;
    }

    public function setMobile($mobile)
    {
        if ($this->mobile != $this->normalizeUkMobile($mobile)) {
            $this->setUpdated(\DateTime::createFromFormat('U', time()));
        }
        $this->mobile = $this->normalizeUkMobile($mobile);
    }
}
