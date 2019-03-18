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

class Lead extends \AppBundle\Document\Lead
{
    /**
     * @Assert\Type("bool")
     * @Assert\IsTrue(message="You must opt in to receive emails")
     * @Assert\NotNull(message="You must opt in to receive emails")
     */
    protected $optin;

    public function getOptin()
    {
        return $this->optin;
    }

    public function setOptin($optin)
    {
        $this->optin = $optin;
    }

    public function toLead()
    {
        $lead = new \AppBundle\Document\Lead();
        $lead->setEmail($this->getEmail());
        $lead->setPhone($this->getPhone());
        $lead->setName($this->getName());
        $lead->setSource($this->getSource());
        $lead->setSourceDetails($this->getSourceDetails());

        return $lead;
    }
}
