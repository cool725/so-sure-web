<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\File\ImeiUploadFile;

class CardRefund
{
    /** @var Policy */
    protected $policy;

    protected $payment;

    protected $amount;

    protected $totalCommission;

    protected $notes;

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    public function getPayment()
    {
        return $this->payment;
    }

    public function setPayment($payment)
    {
        $this->payment = $payment;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount;
    }

    public function getTotalCommission()
    {
        return $this->totalCommission;
    }

    public function setTotalCommission($totalCommission)
    {
        $this->totalCommission = $totalCommission;
    }

    public function getNotes()
    {
        return $this->notes;
    }

    public function setNotes($notes)
    {
        $this->notes = $notes;
    }
}
