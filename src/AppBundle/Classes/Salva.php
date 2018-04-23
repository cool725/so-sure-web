<?php
namespace AppBundle\Classes;

use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Policy;

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

    public function sumBrokerFee($months, $includeFinalCommission)
    {
        if ($months == 12) {
            return self::YEARLY_TOTAL_COMMISSION;
        } elseif ($months >= 1) {
            if ($includeFinalCommission) {
                return $this->toTwoDp(self::MONTHLY_TOTAL_COMMISSION * ($months - 1)
                    + self::FINAL_MONTHLY_TOTAL_COMMISSION);
            } else {
                return $this->toTwoDp(self::MONTHLY_TOTAL_COMMISSION * $months);
            }
        } elseif ($months == 0) {
            return 0;
        } else {
            throw new \Exception('Months can not be negative');
        }
    }

    public function getProrataSplit($commission)
    {
        $rate = $commission / self::YEARLY_TOTAL_COMMISSION;
        if ($rate > 1) {
            throw new \InvalidArgumentException(sprintf('Commission %f is larger than yearly', $commission));
        }

        $broker = $this->toTwoDp($rate * self::YEARLY_BROKER_COMMISSION);
        $coverholderExpected = $this->toTwoDp($rate * self::YEARLY_COVERHOLDER_COMMISSION);

        // In order to avoid errors in calculation due to rounding, only round on 1 element and substract
        // Validate that the expected value is not much than 1p difference
        $coverholderActual = $commission - $broker;
        if ($this->toTwoDp(($coverholderExpected - $coverholderActual)) > 0.01) {
            throw new \Exception(sprintf(
                'Failed to accurately split total commission %f (%f != %f)',
                $commission,
                $coverholderActual,
                $coverholderExpected
            ));
        }

        return [
            'broker' => $broker,
            'coverholder' => $coverholderActual,
        ];
    }
}
