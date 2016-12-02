<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\CurrencyTrait;

class PurchaseStepPhone
{
    use CurrencyTrait;

    /** @var Phone */
    protected $phone;

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

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone(Phone $phone)
    {
        $this->phone = $phone;
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

    public function toApiArray()
    {
        return [
            'phone_id' => $this->getPhone()->getId(),
            'phone' => $this->getPhone()->__toString(),
        ];
    }
}
