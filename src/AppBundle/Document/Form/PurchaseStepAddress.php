<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class PurchaseStepAddress
{
    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @Assert\NotNull()
     */
    protected $addressLine1;

    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     */
    protected $addressLine2;

    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     */
    protected $addressLine3;

    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @Assert\NotNull()
     */
    protected $city;

    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @AppAssert\Postcode()
     * @Assert\NotNull()
     */
    protected $postcode;

    public function setAddressLine1($line1)
    {
        $this->addressLine1 = $line1;
    }

    public function getAddressLine1()
    {
        return $this->addressLine1;
    }

    public function setAddressLine2($line2)
    {
        $this->addressLine2 = $line2;
    }

    public function getAddressLine2()
    {
        return $this->addressLine2;
    }

    public function setAddressLine3($line3)
    {
        $this->addressLine3 = $line3;
    }

    public function getAddressLine3()
    {
        return $this->addressLine3;
    }

    public function setCity($city)
    {
        $this->city = $city;
    }

    public function getCity()
    {
        return $this->city;
    }

    public function setPostcode($postcode)
    {
        $this->postcode = $postcode;
    }

    public function getPostcode()
    {
        return $this->postcode;
    }

    public function toApiArray()
    {
        return [
            'address' => $this->getAddress()->__toString(),
        ];
    }

    public function populateFromUser($user)
    {
        $this->setAddress($user->getBillingAddress());
    }

    public function setAddress(Address $address = null)
    {
        if ($address) {
            $this->setAddressLine1($address->getLine1());
            $this->setAddressLine2($address->getLine2());
            $this->setAddressLine3($address->getLine3());
            $this->setCity($address->getCity());
            $this->setPostcode($address->getPostcode());
        }
    }

    public function populateUser($user)
    {
        $user->setBillingAddress($this->getAddress());
    }

    public function getAddress()
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1($this->getAddressLine1());
        $address->setLine2($this->getAddressLine2());
        $address->setLine3($this->getAddressLine3());
        $address->setCity($this->getCity());
        $address->setPostcode($this->getPostcode());

        return $address;
    }
}
