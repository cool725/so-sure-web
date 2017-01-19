<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class PurchaseStepPersonal
{
    /**
     * @var string
     * @Assert\Email(strict=true)
     * @Assert\NotNull(message="This value is required.")
     */
    protected $email;

    /**
     * @var string
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="1", max="50")
     * @Assert\NotNull(message="This value is required.")
     */
    protected $firstName;

    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @Assert\NotNull(message="This value is required.")
     */
    protected $lastName;

    /**
     * @var string
     * @AppAssert\FullName()
     * @Assert\Length(min="1", max="100")
     * @Assert\NotNull(message="This value is required.")
     */
    protected $name;

    /**
     * @var string
     * @AppAssert\UkMobile()
     * @Assert\NotNull(message="This value is required.")
     */
    protected $mobileNumber;

    /**
     * @var \DateTime
     * @Assert\DateTime()
     * @AppAssert\Age()
     * @Assert\NotNull(message="Date of Birth is required.")
     */
    protected $birthday;

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

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = trim($name);
        $parts = explode(" ", trim($name));
        if (count($parts) == 2) {
            $this->setFirstName(ucfirst($parts[0]));
            $this->setLastName(ucfirst($parts[1]));
        }
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

    public function toApiArray()
    {
        return [
            'email' => $this->getEmail(),
            'firstName' => $this->getFirstName(),
            'lastName' => $this->getLastName(),
            'mobileNumber' => $this->getMobileNumber(),
        ];
    }

    public function populateFromUser($user)
    {
        $this->setEmail($user->getEmail());
        $this->setFirstName($user->getFirstName());
        $this->setLastName($user->getLastName());
        $this->setName(trim(sprintf('%s %s', $user->getFirstName(), $user->getLastName())));
        $this->setMobileNumber($user->getMobileNumber());
        $this->setBirthday($user->getBirthday());
    }

    public function populateUser($user)
    {
        $user->setEmail($this->getEmail());
        $user->setFirstName($this->getFirstName());
        $user->setLastName($this->getLastName());
        $user->setMobileNumber($this->getMobileNumber());
        $user->setBirthday($this->getBirthday());
    }
}
