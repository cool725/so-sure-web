<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use AppBundle\Document\User;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\PhoneTrait;

class PurchaseStepPersonalAddress
{
    use PhoneTrait;

    protected $user;

    /**
     * @var string
     * @Assert\Email(strict=false)
     * @Assert\NotBlank(message="This value is required.")
     */
    protected $email;

    /**
     * @var string
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="1", max="50")
     * @Assert\NotBlank(message="This value is required.")
     */
    protected $firstName;

    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @Assert\NotBlank(message="This value is required.")
     */
    protected $lastName;

    /**
     * @var boolean
     */
    protected $userOptIn;

    /**
     * @var string
     * @AppAssert\UkMobile()
     * @Assert\NotBlank(message="This value is required.")
     */
    protected $mobileNumber;

    /**
     * @var \DateTime
     * @Assert\DateTime()
     * @AppAssert\Age()
     * @Assert\NotBlank(message="Date of Birth is required.")
     */
    protected $birthday;

    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @Assert\NotBlank(message="Address is required.")
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
     * @Assert\NotBlank(message="City is required.")
     */
    protected $city;

    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="10")
     * @AppAssert\Postcode()
     * @Assert\NotBlank(message="Postcode is required.")
     */
    protected $postcode;

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

    public function getUserOptIn()
    {
        return $this->userOptIn;
    }

    public function setUserOptIn($userOptIn)
    {
        $this->userOptIn = $userOptIn;
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
        $this->mobileNumber = $this->normalizeUkMobile($mobileNumber);
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

    public function toApiArray()
    {
        return [
            'email' => $this->getEmail(),
            'firstName' => $this->getFirstName(),
            'lastName' => $this->getLastName(),
            'mobileNumber' => $this->getMobileNumber(),
            'address' => $this->getAddress()->__toString(),
        ];
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    public function populateFromUser($user)
    {
        $this->user = $user;
        $this->setEmail($user->getEmail());
        $this->setFirstName($user->getFirstName());
        // $this->setUserOptIn($user->getUserOptIn());
        $this->setLastName($user->getLastName());
        $this->setMobileNumber($user->getMobileNumber());
        $this->setBirthday($user->getBirthday());
        $this->setAddress($user->getBillingAddress());
    }

    public function populateUser($user)
    {
        $user->setEmail($this->getEmail());
        $user->setFirstName($this->getFirstName());
        $user->setLastName($this->getLastName());
        // $user->setUserOptIn($this->getUserOptIn());
        $user->setMobileNumber($this->getMobileNumber());
        $user->setBirthday($this->getBirthday());
        $user->setBillingAddress($this->getAddress());
    }

    public function matchesUser($user)
    {
        //\Doctrine\Common\Util\Debug::dump($this);
        //\Doctrine\Common\Util\Debug::dump($user);
        $match = mb_strtolower($this->getEmail()) == $user->getEmailCanonical() &&
            $this->getFirstName() == $user->getFirstName() &&
            $this->getLastName() == $user->getLastName() &&
            $this->getMobileNumber() == $user->getMobileNumber() &&
            $this->getBirthday()->diff($user->getBirthday())->days == 0;
        //print $match ? 'match' : 'no match';

        return $match;
    }
}
