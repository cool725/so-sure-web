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
    protected $cardToken;

    /**
     * @MongoDB\Field(type="string")
     */
    protected $cardTokenHash;

    /**
     * @MongoDB\Field(type="string")
     */
    protected $deviceDna;

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
        $this->cardToken = $key;
        $this->cardTokens[$key] = $value;
        $this->setCardTokenHash(md5(serialize($value)));
    }

    public function getCardToken()
    {
        return $this->cardToken;
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

    public function getDeviceDna()
    {
        return $this->deviceDna;
    }

    public function setDeviceDna($deviceDna)
    {
        $this->deviceDna = $deviceDna;
    }

    public function getDecodedDeviceDna()
    {
        if (!$this->deviceDna) {
            return null;
        }

        $data = null;
        try {
            $data = json_decode($this->deviceDna);

            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }
}
