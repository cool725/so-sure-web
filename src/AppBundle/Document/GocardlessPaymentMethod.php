<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/** @MongoDB\EmbeddedDocument */
class GocardlessPaymentMethod extends PaymentMethod
{
    /** @MongoDB\Field(type="string") */
    protected $customerId;

    // TODO: Add ordering
    /**
     * @MongoDB\Field(type="hash")
     * @var array
     */
    protected $accounts = array();

    /** @MongoDB\Field(type="hash") */
    protected $mandates = array();

    /** @MongoDB\Field(type="hash") */
    protected $subscriptions = array();

    /** @MongoDB\Field(type="collection") */
    protected $accountHashes = array();

    public function setCustomerId($customerId)
    {
        $this->customerId = $customerId;
    }

    public function getCustomerId()
    {
        return $this->customerId;
    }

    public function addAccount($key, $value)
    {
        $this->accounts[$key] = $value;
        $data = json_decode($value, true);
        if (isset($data['account_hash'])) {
            $this->accountHashes[] = $data['account_hash'];
        }
    }

    /**
     * @return array
     */
    public function getAccounts()
    {
        return $this->accounts;
    }

    public function hasAccounts()
    {
        return count($this->getAccounts()) > 0;
    }

    public function getAccountHashes()
    {
        return $this->accountHashes;
    }

    /**
     * TODO: Some way to determining primary account
     */
    public function getPrimaryAccount()
    {
        if (count($this->getAccounts()) > 0) {
            $accounts = $this->getAccounts();
            if ($account = reset($accounts)) {
                return json_decode($account);
            }
        }

        return null;
    }

    public function hasPrimaryAccount()
    {
        return $this->getPrimaryAccount() !== null;
    }

    public function addMandate($key, $value)
    {
        $this->mandates[$key] = $value;
    }

    public function getMandates()
    {
        return $this->mandates;
    }

    public function hasMandates()
    {
        return count($this->getMandates()) > 0;
    }

    public function addSubscription($key, $value)
    {
        $this->subscriptions[$key] = $value;
    }

    public function getSubscriptions()
    {
        return $this->subscriptions;
    }

    public function hasSubscription()
    {
        return count($this->getSubscriptions()) > 0;
    }

    public function isValid()
    {
        return $this->hasSubscription();
    }

    public function __toString()
    {
        // TODO: Implement __toString() method.
        return '';
    }
}
