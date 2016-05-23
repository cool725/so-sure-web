<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/** @MongoDB\EmbeddedDocument */
class JudoPaymentMethod extends PaymentMethod
{
    /**
     * @MongoDB\Field(type="string")
     */
    protected $customerToken;

    /**
     * @MongoDB\Field(type="hash")
     */
    protected $cardTokens = array();

    public function setCustomerToken($customerToken)
    {
        $this->customerToken = $customerToken;
    }

    public function getCustomerToken()
    {
        return $this->customerToken;
    }

    public function addCardToken($key, $value)
    {
        $this->cardTokens[$key] = $value;
    }

    public function getCardTokens()
    {
        return $this->cardTokens;
    }

    public function hasCardTokens()
    {
        return count($this->getCardTokens()) > 0;
    }
}
