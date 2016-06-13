<?php
namespace AppBundle\Classes;

use AppBundle\Document\CurrencyTrait;

class Salva
{
    use CurrencyTrait;

    const YEARLY_BROKER_FEE = 10;
    const MONTHLY_BROKER_FEE = 0.83;
    const FINAL_MONTHLY_BROKER_FEE = 0.87;
    const SALVA_TIMEZONE = "Europe/London";

    public function sumBrokerFee($months)
    {
        if ($months == 12) {
            return self::YEARLY_BROKER_FEE;
        }

        return $this->toTwoDp(self::MONTHLY_BROKER_FEE * $months);
    }
}
