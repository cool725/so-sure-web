<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/** @MongoDB\EmbeddedDocument */
class Gocardless
{
    /** @MongoDB\Field(type="string", name="customerid", nullable=true) */
    protected $customerId;

    // TODO: Add ordering
    /** @MongoDB\Field(type="hash", name="accounts", nullable=true) */
    protected $accounts = array();

    /** @MongoDB\Field(type="hash", name="mandates", nullable=true) */
    protected $mandates = array();

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
    }

    public function getAccounts()
    {
        return $this->accounts;
    }

    public function hasAccounts()
    {
        return count($this->getAccounts()) > 0;
    }

    /**
     * TODO: Some way to determining primary account
     */
    public function getPrimaryAccount()
    {
        if (count($this->getAccounts() > 0)) {
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
}
