<?php
namespace AppBundle\Classes;

use AppBundle\Document\CurrencyTrait;

class Salva
{
    use CurrencyTrait;

    const YEARLY_TOTAL_COMMISSION = 10.72; // 10.00 broker fee + 0.72 afl
    const YEARLY_COVERHOLDER_COMMISSION = 10; // 10.00 broker fee + 0.72 afl
    const YEARLY_BROKER_COMMISSION = 0.72; // 10.00 broker fee + 0.72 afl

    const MONTHLY_TOTAL_COMMISSION  = 0.89; // 0.83 broker fee + 0.06 afl
    const MONTHLY_COVERHOLDER_COMMISSION = 0.83; // 0.83 broker fee + 0.06 afl
    const MONTHLY_BROKER_COMMISSION = 0.06; // 0.83 broker fee + 0.06 afl

    const FINAL_MONTHLY_TOTAL_COMMISSION = 0.93; // 0.87 broker fee + 0.06 afl
    const FINAL_MONTHLY_COVERHOLDER_COMMISSION = 0.87; // 0.87 broker fee + 0.06 afl
    const FINAL_MONTHLY_BROKER_COMMISSION = 0.06; // 0.87 broker fee + 0.06 afl

    const SALVA_TIMEZONE = "Europe/London";

    public function sumBrokerFee($months)
    {
        if ($months == 12) {
            return self::YEARLY_TOTAL_COMMISSION;
        }

        return $this->toTwoDp(self::MONTHLY_TOTAL_COMMISSION * $months);
    }
}
