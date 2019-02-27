<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\BankAccount;
use AppBundle\Document\Address;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\BacsTrait;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class Sequence
{
    /**
     * @Assert\Type("digit")
     * @var integer
     */
    protected $seq;

    public function getSeq()
    {
        return $this->seq;
    }

    public function setSeq($seq)
    {
        $this->seq = $seq;
    }
}
