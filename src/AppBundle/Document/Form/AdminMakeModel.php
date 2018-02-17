<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\File\ImeiUploadFile;

class AdminMakeModel
{
    use PhoneTrait;

    /**
     * @var string
     * @AppAssert\Imei()
     */
    protected $imei;

    /**
     * @var string
     * @AppAssert\SerialNumber()
     */
    protected $serialNumber;

    public function getImei()
    {
        return $this->imei;
    }

    public function setImei($imei)
    {
        $this->imei = $this->normalizeImei($imei);
    }

    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = $serialNumber;
    }

    /**
     * @Assert\NotNull(message="Imei or Serial Number is required to run check")
     */
    public function getSerialNumberOrImei()
    {
        if ($this->getSerialNumber()) {
            return $this->getSerialNumber();
        } elseif ($this->getImei()) {
            return $this->getImei();
        }

        return null;
    }
}
