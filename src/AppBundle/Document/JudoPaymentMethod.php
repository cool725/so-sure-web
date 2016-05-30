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

    /**
     * @MongoDB\Field(type="string")
     */
    protected $cardTokenHash;

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
        $this->setCardTokenHash(md5(serialize($value)));
    }

    public function getCardTokens()
    {
        return $this->cardTokens;
    }

    public function hasCardTokens()
    {
        return count($this->getCardTokens()) > 0;
    }

    public function setCardTokenHash($cardTokenHash)
    {
        $this->cardTokenHash = $cardTokenHash;
    }

    public function getCardTokenHash()
    {
        return $this->cardTokenHash;
    }
}
