<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\Salva;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Payment\JudoPayment;

/**
 * @group unit
 */
class SalvaTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Makes sure that sum coverholder commission works correctly.
     */
    public function testSumCoverholderCommission()
    {
        $normal = Salva::MONTHLY_COVERHOLDER_COMMISSION;
        $last = Salva::FINAL_MONTHLY_COVERHOLDER_COMMISSION;
        $yearly = Salva::YEARLY_COVERHOLDER_COMMISSION;
        $salva = new Salva();
        $this->assertEquals(0, $salva->sumCoverholderCommission(0, false));
        $this->assertEquals(0, $salva->sumCoverholderCommission(0, true));
        $this->assertEquals($normal, $salva->sumCoverholderCommission(1, false));
        $this->assertEquals($last, $salva->sumCoverholderCommission(1, true));
        $this->assertEquals($normal * 2, $salva->sumCoverholderCommission(2, false));
        $this->assertEquals($normal + $last, $salva->sumCoverholderCommission(2, true));
        $this->assertEquals($normal * 3, $salva->sumCoverholderCommission(3, false));
        $this->assertEquals($normal * 2 + $last, $salva->sumCoverholderCommission(3, true));
        $this->assertEquals($normal * 4, $salva->sumCoverholderCommission(4, false));
        $this->assertEquals($normal * 3 + $last, $salva->sumCoverholderCommission(4, true));
        $this->assertEquals($normal * 5, $salva->sumCoverholderCommission(5, false));
        $this->assertEquals($normal * 4 + $last, $salva->sumCoverholderCommission(5, true));
        $this->assertEquals($normal * 6, $salva->sumCoverholderCommission(6, false));
        $this->assertEquals($normal * 5 + $last, $salva->sumCoverholderCommission(6, true));
        $this->assertEquals($normal * 7, $salva->sumCoverholderCommission(7, false));
        $this->assertEquals($normal * 6 + $last, $salva->sumCoverholderCommission(7, true));
        $this->assertEquals($normal * 8, $salva->sumCoverholderCommission(8, false));
        $this->assertEquals($normal * 7 + $last, $salva->sumCoverholderCommission(8, true));
        $this->assertEquals($normal * 9, $salva->sumCoverholderCommission(9, false));
        $this->assertEquals($normal * 8 + $last, $salva->sumCoverholderCommission(9, true));
        $this->assertEquals($normal * 10, $salva->sumCoverholderCommission(10, false));
        $this->assertEquals($normal * 9 + $last, $salva->sumCoverholderCommission(10, true));
        $this->assertEquals($normal * 11, $salva->sumCoverholderCommission(11, false));
        $this->assertEquals($normal * 10 + $last, $salva->sumCoverholderCommission(11, true));
        $this->assertEquals($yearly, $salva->sumCoverholderCommission(12, false));
        $this->assertEquals($yearly, $salva->sumCoverholderCommission(12, true));
        $this->assertEquals($normal * 13, $salva->sumCoverholderCommission(13, false));
        $this->assertEquals($normal * 12 + $last, $salva->sumCoverholderCommission(13, true));
    }
}
