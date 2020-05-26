<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\File\ImeiUploadFile;

class PurchaseStepPayment
{
    use CurrencyTrait;
    use PhoneTrait;

    /** @var Policy */
    protected $policy;

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

    protected $new;

    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="6", max="8")
     */
    protected $promoCode;

    /** @var array */
    protected $prices = [];

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

    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * Sets the amount on the payment step and validates that it is an acceptable amount.
     * @param float $amount is the amount that we are setting to pay.
     */
    public function setAmount($amount)
    {
        $additionalPremium = $this->getUser()->getAdditionalPremium();
        foreach ($this->getPrices() as $price) {
            if ($this->areEqualToTwoDp($amount, $price->getMonthlyPremiumPrice($additionalPremium)) ||
                $this->areEqualToTwoDp($amount, $price->getYearlyPremiumPrice($additionalPremium))
            ) {
                $this->amount = $amount;
                return;
            }
        }
        throw new \InvalidArgumentException(sprintf('Amount must be a monthly or annual figure'));
    }

    public function getNew()
    {
        return $this->new;
    }

    public function setNew($new)
    {
        $this->new = $new;
    }

    public function getPromoCode()
    {
        return $this->promoCode;
    }

    public function setPromoCode($promoCode)
    {
        $this->promoCode = $promoCode;
    }

    /**
     * Gives the list of allowed prices.
     * @return array containing the prices.
     */
    public function getPrices()
    {
        return $this->prices;
    }

    /**
     * Adds a price to the list of prices.
     * @param PhonePrice $price is the price to add to the list.
     */
    public function addPrice(PhonePrice $price)
    {
        $this->prices[] = $price;
    }

    public function allowedAmountChange()
    {
        if ($this->getNew()) {
            return true;
        }

        return true;
    }

    public function toApiArray()
    {
        return [
            'phone_id' => $this->getPolicy()->getPhone()->getId(),
            'phone' => $this->getPolicy()->getPhone()->__toString(),
        ];
    }
}
