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

    public function setAmount($amount)
    {
        $additionalPremium = $this->getUser()->getAdditionalPremium();
        $price = $this->getPolicy()->getPhone()->getCurrentPhonePrice();
        if (!$this->areEqualToTwoDp($amount, $price->getMonthlyPremiumPrice($additionalPremium)) &&
            !$this->areEqualToTwoDp($amount, $price->getYearlyPremiumPrice($additionalPremium))) {
            throw new \InvalidArgumentException(sprintf('Amount must be a monthly or annual figure'));
        }
        $this->amount = $amount;
    }

    public function getNew()
    {
        return $this->new;
    }

    public function setNew($new)
    {
        $this->new = $new;
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
            'phone_id' => $this->getPolicy()->getPhone()->getId(),
            'phone' => $this->getPolicy()->getPhone()->__toString(),
        ];
    }
}
