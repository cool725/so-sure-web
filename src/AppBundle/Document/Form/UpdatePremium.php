<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Policy;
use AppBundle\Document\Premium;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represents a request to create a scheduled payment via a form.
 */
class UpdatePremium
{

    /**
     * @var float
     */
    protected $amount;

    /**
     * @var float
     */
    protected $previousGwp;

    /**
     * @var float
     */
    protected $previousIPT;

    /**
     * @var float
     */
    protected $gwp;

    /**
     * @var float
     */
    protected $ipt;

    /**
     * @var string
     */
    protected $paytype;

    /**
     * @var \DateTime
     * @Assert\NotNull(message="Date must be set")
     */
    protected $date;

    /**
     * @var string
     * @Assert\NotNull(message="Must justify manual payment")
     */
    protected $notes;

    /**
     * @var string
     */
    protected $emailPreference;

    /**
     * @var Policy
     */
    protected $policy;

    /**
     * @var Premium
     */
    protected $premium;

    public function getEmailPreference()
    {
        return $this->emailPreference;
    }

    public function setEmailPreference($emailPreference)
    {
        $this->emailPreference = $emailPreference;
    }

    /**
     * @return float
     */
    public function getAmount()
    {
        return round($this->amount, 2);
    }

    /**
     * @param float $amount
     * @return UpdatePremium
     */
    public function setAmount($amount)
    {
        $this->amount = $amount;
        return $this;
    }

    /**
     * @param string $planType
     * @return UpdatePremium
     */
    public function setPaytype(string $planType)
    {
        $this->paytype = $planType;
        return $this;
    }

    /**
     * @return string
     */
    public function getPaytype()
    {
        return$this->paytype;
    }


    /**
     * @return float
     */
    public function getGwp()
    {
        return $this->gwp;
    }

    /**
     * @param float $gwp
     * @return UpdatePremium
     */
    public function setGwp($gwp)
    {
        $this->gwp = $gwp;
        return $this;
    }

    /**
     * @param float $amount
     * @return UpdatePremium
     */
    public function calcGwp($amount)
    {
        $this->gwp = $amount / 1.12;

        return $this;
    }

    /**
     * @return Premium
     */
    public function getPremium()
    {
        return $this->premium;
    }

    /**
     * @param Premium $premium
     * @return UpdatePremium
     */
    public function setPremium($premium)
    {
        $this->premium = $premium;
        return $this;
    }

    /**
     * @return float
     */
    public function getIpt()
    {
        return $this->ipt;
    }

    /**
     * @param float $ipt
     * @return UpdatePremium
     */
    public function setIpt($ipt)
    {
        $this->ipt = $ipt;
        return $this;
    }


    public function calcIpt()
    {
        $this->ipt = $this->gwp * 0.12;
        return $this;
    }

    /**
     * @return float
     */
    public function getPreviousGwp()
    {
        return $this->previousGwp;
    }

    /**
     * @param float $previousGwp
     * @return UpdatePremium
     */
    public function setPreviousGwp($previousGwp)
    {
        $this->previousGwp = $previousGwp;
        return $this;
    }

    /**
     * @return float
     */
    public function getPreviousIPT()
    {
        return $this->previousIPT;
    }

    /**
     * @param float $previousIPT
     * @return UpdatePremium
     */
    public function setPreviousIPT($previousIPT)
    {
        $this->previousIPT = $previousIPT;
        return $this;
    }

    public function getDate() :\DateTime
    {
        return $this->date;
    }

    public function setDate(\DateTime $date)
    {
        $this->date = $date;
    }

    /**
     * @return mixed
     */
    public function getNotes()
    {
        return $this->notes;
    }

    /**
     * @param mixed $notes
     * @return UpdatePremium
     */
    public function setNotes($notes)
    {
        $this->notes = $notes;
        return $this;
    }

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
        return $this;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function __construct()
    {

    }
}
