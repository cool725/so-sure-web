<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\PhoneTrait;

class PurchaseStepPhone
{
    use CurrencyTrait;
    use PhoneTrait;

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

    /**
     * @var string
     * @Assert\IsTrue(message="You must agree to our terms")
     */
    protected $agreed;

    protected $new;

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
        $this->imei = $this->normalizeImei($imei);
        if ($this->getPhone() && $this->getPhone()->getMake() != "Apple") {
            $this->setSerialNumber($this->imei);
        }
    }

    public function getNew()
    {
        return $this->new;
    }

    public function setNew($new)
    {
        $this->new = $new;
    }

    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = str_replace(' ', '', $serialNumber);
    }
    
    public function setAgreed($agreed)
    {
        $this->agreed = $agreed;
    }

    public function isAgreed()
    {
        return $this->agreed;
    }

    public function allowedAmountChange()
    {
        if ($this->getNew()) {
            return true;
        }

        return !$this->isAgreed();
    }

    public function toApiArray()
    {
        return [
            'phone_id' => $this->getPhone()->getId(),
            'phone' => $this->getPhone()->__toString(),
        ];
    }
}
