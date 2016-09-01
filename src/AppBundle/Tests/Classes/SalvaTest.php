<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\Salva;

/**
 * @group unit
 */
class SalvaTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testSumBrokerFee()
    {
        $salva = new Salva();

        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $salva->sumBrokerFee(12, false));
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $salva->sumBrokerFee(12, true));
        $this->assertEquals(0, $salva->sumBrokerFee(0, false));
        $this->assertEquals(0, $salva->sumBrokerFee(0, true));
        $this->assertEquals(Salva::MONTHLY_TOTAL_COMMISSION, $salva->sumBrokerFee(1, false));
        $this->assertEquals(Salva::FINAL_MONTHLY_TOTAL_COMMISSION, $salva->sumBrokerFee(1, true));

        $this->assertEquals(Salva::MONTHLY_TOTAL_COMMISSION * 2, $salva->sumBrokerFee(2, false));
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION + Salva::FINAL_MONTHLY_TOTAL_COMMISSION,
            $salva->sumBrokerFee(2, true)
        );

        $this->assertEquals(Salva::MONTHLY_TOTAL_COMMISSION * 3, $salva->sumBrokerFee(3, false));
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION * 2+ Salva::FINAL_MONTHLY_TOTAL_COMMISSION,
            $salva->sumBrokerFee(3, true)
        );
    }
}
