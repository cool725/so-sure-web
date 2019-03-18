<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\File\ImeiUploadFile;

class PurchaseStepPhone
{
    use CurrencyTrait;
    use PhoneTrait;

    /** @var Phone */
    protected $phone;

    /** @var Policy */
    protected $policy;

    /** @var User */
    protected $user;

    protected $file;
    
    /**
     * @Assert\IsTrue(message="Unable to find an IMEI number in the file")
     */
    protected $fileValid = true;

    /**
     * @var string
     * @AppAssert\Imei()
     * @Assert\NotBlank(message="IMEI is required.")
     */
    protected $imei;

    /**
     * @var string
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="5", max="32",
     *  minMessage="This doesn't appear to be a valid serial number",
     *  maxMessage="This doesn't appear to be a valid serial number")
     */
    protected $serialNumber;
    
    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone(Phone $phone)
    {
        $this->phone = $phone;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser($user)
    {
        $this->user = $user;
    }

    public function getImei()
    {
        return $this->imei;
    }

    public function setImei($imei)
    {
        $this->imei = $this->normalizeImei($imei);
        if ($this->getPhone() && $this->getPhone()->getMake() != "Apple") {
            $this->setSerialNumber($this->imei);
        }
    }

    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = str_replace(' ', '', $serialNumber);
    }

    public function getFile()
    {
        return $this->file;
    }

    public function setFile($file)
    {
        $this->file = $file;
    }

    public function getFileValid()
    {
        return $this->fileValid;
    }

    public function setFileValid($fileValid)
    {
        $this->fileValid = $fileValid;
    }

    /**
     * @Assert\IsFalse(message="Serial Number is required.")
     */
    public function isApplePhoneWithoutSerialNumber()
    {
        if (!$this->getPhone()) {
            return true;
        }
        if (!$this->getPhone()->isApple()) {
            return false;
        }

        return mb_strlen(trim($this->getSerialNumber())) == 0;
    }

    public function toApiArray()
    {
        return [
            'phone_id' => $this->getPhone()->getId(),
            'phone' => $this->getPhone()->__toString(),
        ];
    }
}
