<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class Purchase
{
    use CurrencyTrait;

    /** @var Phone */
    protected $phone;
    
    /**
     * @var string
     * @Assert\Email(strict=true)
     * @Assert\NotNull()
     */
    protected $email;

    /**
     * @var string
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="1", max="50")
     * @Assert\NotNull()
     */
    protected $firstName;

    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @Assert\NotNull()
     */
    protected $lastName;

    /**
     * @var string
     * @AppAssert\UkMobile()
     * @Assert\NotNull()
     */
    protected $mobileNumber;

    /**
     * @var \DateTime
     * @Assert\DateTime()
     * @AppAssert\Age()
     * @Assert\NotNull()
     */
    protected $birthday;

    /**
     * @var string
     * @AppAssert\Imei()
     * @Assert\NotNull()
     */
    protected $imei;

    /**
     * @var string
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="5", max="32",
     *  minMessage="This doesn't appear to be a valid serial number",
     *  maxMessage="This doesn't appear to be a valid serial number")
     * @Assert\NotNull()
     */
    protected $serialNumber;

    /**
     * @Assert\Range(
     *      min = 1,
     *      max = 200,
     *      minMessage = "You must select monthly or annual policy payments",
     *      maxMessage = "You must select monthly or annual policy payments"
     * )
     * @Assert\NotNull()
     */
    protected $amount;

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

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone(Phone $phone)
    {
        $this->phone = $phone;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = $email;
    }

    public function getFirstName()
    {
        return $this->firstName;
    }

    public function setFirstName($firstName)
    {
        $this->firstName = $firstName;
    }

    public function getLastName()
    {
        return $this->lastName;
    }

    public function setLastName($lastName)
    {
        $this->lastName = $lastName;
    }

    public function getBirthday()
    {
        return $this->birthday;
    }

    public function setBirthday($birthday)
    {
        if (is_string($birthday)) {
            $this->birthday = \DateTime::createFromFormat('d/m/Y', $birthday);
        } elseif ($birthday instanceof \DateTime) {
            $this->birthday = $birthday;
        }
    }

    public function getMobileNumber()
    {
        return $this->mobileNumber;
    }

    public function setMobileNumber($mobileNumber)
    {
        $this->mobileNumber = $mobileNumber;
    }

    public function getImei()
    {
        return $this->imei;
    }

    public function setImei($imei)
    {
        $this->imei = $imei;
        if ($this->getPhone()->getMake() != "Apple") {
            $this->setSerialNumber($imei);
        }
    }

    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = $serialNumber;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function setAmount($amount)
    {
        $price = $this->getPhone()->getCurrentPhonePrice();
        if (!$this->areEqualToTwoDp($amount, $price->getMonthlyPremiumPrice()) &&
            !$this->areEqualToTwoDp($amount, $price->getYearlyPremiumPrice())) {
            throw new \InvalidArgumentException(sprintf('Amount must be a monthly or annual figure'));
        }
        $this->amount = $amount;
    }

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
            'email' => $this->getEmail(),
            'firstName' => $this->getFirstName(),
            'lastName' => $this->getLastName(),
            'mobileNumber' => $this->getMobileNumber(),
            'phone_id' => $this->getPhone()->getId(),
            'phone' => $this->getPhone()->__toString(),
            'birthday' => $this->getBirthday() ? $this->getBirthday()->format(\DateTime::ATOM) : null,
            'address' => $this->getAddress()->__toString(),
        ];
    }

    public function populateFromUser($user)
    {
        $this->setEmail($user->getEmail());
        $this->setFirstName($user->getFirstName());
        $this->setLastName($user->getLastName());
        $this->setMobileNumber($user->getMobileNumber());
        $this->setBirthday($user->getBirthday());
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
        $user->setEmail($this->getEmail());
        $user->setFirstName($this->getFirstName());
        $user->setLastName($this->getLastName());
        $user->setMobileNumber($this->getMobileNumber());
        $user->setBirthday($this->getBirthday());
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
