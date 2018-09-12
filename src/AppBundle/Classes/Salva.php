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

    public static $commissionValidationExclusions = [
        '5960afe142bece15ca46c796',
        '5963fe30e57c396d46347475',
        '596765ef42bece52d026aa65',
        '5970d065b674b62bac4be365',
        '5973293aa603ad542d4ed949',
        '594a3f642a964c01fc294435',
        '5954b2382a964c2b461c8f35',
        '596ddd2ec188843b4878e765',
        '59cfae48aff01f609c59b085',
        '59dc0eb8f4a90d4b2622b55d',
        '59de9d9175435e233d6c06a5',
        '59e7202b446b0f221f7e1935',
        '59fc527dc502d078f04b92b5',
        '5ab298b4332fe80e4517791d',
        '5ab62a8e75435e720828dd75',
        '5ac7fd271eae6236c1275b3c',
        '5aca36be75435e1ab25efe35',
        '593ff42910e6a948c85d46c8', // commission diff agreed w/salva
        '59651377e57c3944794d5735', // commission diff agreed w/salva
        '59cf6ae0aa996c16b73ea845', // commission diff agreed w/salva
        '5a2e3c150eb25b58ae1e9c57', // commission diff agreed w/salva
        '59afaa64e6759b15cc52eee7', // commission diff agreed w/salva
        '5a0421dec502d01a414f5b53', // commission diff agreed w/salva
        '5a4681720eb25b15aa49f227', // commission diff agreed w/salva
        '58341bfc1d255d3f0a6641c9', // commission diff agreed w/salva
        '5ada39a375435e4af22daa45', // refund was provided
        '5ab17d0754e50f610f289ab5', // refund was provided
        '5a0421dec502d01a414f5b53', // commission diff agreed w/salva
    ];

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
