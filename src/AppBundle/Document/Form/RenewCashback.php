<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\PhonePolicy;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\Policy;
use AppBundle\Document\Cashback;

class RenewCashback extends Renew
{
    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\NotNull(message="Account Name is required.")
     * @Assert\Length(min="2", max="100")
     */
    protected $accountName;

    /**
     * @AppAssert\SortCode()
     * @Assert\NotNull(message="Sort code is required.")
     * @Assert\Length(min="6", max="6")
     */
    protected $sortCode;

    /**
     * @AppAssert\BankAccountNumber()
     * @Assert\NotNull(message="Account Number is required.")
     * @Assert\Length(min="8", max="8")
     */
    protected $accountNumber;

    public function getAccountName()
    {
        return $this->accountName;
    }

    public function setAccountName($accountName)
    {
        $this->accountName = $accountName;
    }

    public function getSortCode()
    {
        return $this->sortCode;
    }

    public function setSortCode($sortCode)
    {
        $this->sortCode = str_replace('-', '', $sortCode);
    }

    public function getAccountNumber()
    {
        return $this->accountNumber;
    }

    public function setAccountNumber($accountNumber)
    {
        $this->accountNumber = $accountNumber;
    }

    public function useDefaultAmount()
    {
        $usePot = $this->getPolicy()->getPotValue() > 0 ? 1 : 0;
        if ($this->getPolicy()->getPremiumPlan() == Policy::PLAN_MONTHLY) {
            $this->setEncodedAmount(implode("|", [$this->getAdjustedStandardMonthlyPremiumPrice(), 12, $usePot]));
        } elseif ($this->getPolicy()->getPremiumPlan() == Policy::PLAN_YEARLY) {
            $this->setEncodedAmount(implode("|", [$this->getAdjustedYearlyPremiumPrice(), 1, $usePot]));
        }
    }

    public function createCashback()
    {
        if ($this->getUsePot()) {
            throw new \Exception(sprintf('Cashbash should not use pot'));
        }
        if ($this->getPolicy()->getPotValue() <= 0) {
            throw new \Exception('Cashback required pot with value in it');
        }

        $cashback = new Cashback();
        $cashback->setDate(\DateTime::createFromFormat('U', time()));
        $cashback->setAccountName($this->getAccountName());
        $cashback->setAccountNumber($this->getAccountNumber());
        $cashback->setSortCode($this->getSortCode());
        $cashback->setStatus(Cashback::STATUS_PENDING_CLAIMABLE);
        $this->getPolicy()->setCashback($cashback);

        return $cashback;
    }
}
