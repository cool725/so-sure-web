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

    /** @var User */
    protected $user;

    /**
     * @Assert\Range(
     *      min = 1,
     *      max = 200,
     *      minMessage = "You must select monthly or annual policy payments",
     *      maxMessage = "You must select monthly or annual policy payments"
     * )
     * @Assert\NotNull(message="You must select monthly or annual policy payments")
     */
    protected $amount;

    /**
     * @var string
     * @AppAssert\Imei()
     * @Assert\NotNull(message="IMEI is required.")
     */
    protected $imei;

    /**
     * @var string
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="5", max="32",
     *  minMessage="This doesn't appear to be a valid serial number",
     *  maxMessage="This doesn't appear to be a valid serial number")
     * @Assert\NotNull(message="Serial Number is required.")
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

    public function getUser()
    {
        return $this->user;
    }

    public function setUser($user)
    {
        $this->user = $user;
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
        // space, -, / may be present when copy/pasted by user
        $imei = str_replace(' ', '', $imei);
        $imei = str_replace('-', '', $imei);
        $imei = str_replace('/', '', $imei);
        // There are some cases of 17 digits imei (15 digit imei with additional info attached)
        // Such as samsung s7 edge
        $this->imei = substr($imei, 0, 15);
        if ($this->getPhone() && $this->getPhone()->getMake() != "Apple") {
            $this->setSerialNumber($imei);
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

    public function toApiArray()
    {
        return [
            'phone_id' => $this->getPhone()->getId(),
            'phone' => $this->getPhone()->__toString(),
        ];
    }
}
