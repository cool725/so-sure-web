<?php
namespace AppBundle\Classes;

use AppBundle\Document\CurrencyTrait;

class Salva
{
    use CurrencyTrait;

    const YEARLY_BROKER_FEE = 10.72; // 10.00 broker fee + 0.72 afl
    const MONTHLY_BROKER_FEE = 0.89; // 0.83 broker fee + 0.06 afl
    const FINAL_MONTHLY_BROKER_FEE = 0.93; // 0.87 broker fee + 0.06 afl
    const SALVA_TIMEZONE = "Europe/London";

    public function sumBrokerFee($months)
    {
        if ($months == 12) {
            return self::YEARLY_BROKER_FEE;
        }

        return $this->toTwoDp(self::MONTHLY_BROKER_FEE * $months);
    }
}
