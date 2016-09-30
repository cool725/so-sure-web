<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\Salva;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\JudoPayment;

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

    public function testProrataSplit()
    {
        $salva = new Salva();

        // max
        $this->assertEquals(10, $salva->getProrataSplit(10.72)['coverholder']);
        $this->assertEquals(0.72, $salva->getProrataSplit(10.72)['broker']);

        // min
        $this->assertEquals(0, $salva->getProrataSplit(0)['coverholder']);
        $this->assertEquals(0, $salva->getProrataSplit(0)['broker']);

        // middle
        $this->assertEquals(4.78, $salva->getProrataSplit(5.12)['coverholder']);
        $this->assertEquals(0.34, $salva->getProrataSplit(5.12)['broker']);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testProrataSplitExceeded()
    {
        $salva = new Salva();
        $salva->getProrataSplit(10.73);
    }

    public function testTotalCommission()
    {
        $salva = new Salva();

        $policy = new SalvaPhonePolicy();
        $policy->setPremium(new PhonePremium());
        $this->assertEquals(null, $salva->getTotalCommission($policy));

        $policy->setPremiumInstallments(1);
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $salva->getTotalCommission($policy));

        $policy->setPremiumInstallments(12);
        $this->assertEquals(Salva::MONTHLY_TOTAL_COMMISSION, $salva->getTotalCommission($policy));
    }

    public function testTotalCommissionFinalMonth()
    {
        $salva = new Salva();

        $policy = new SalvaPhonePolicy();
        $policy->setPremiumInstallments(12);
        $policy->setStatus(SalvaPhonePolicy::STATUS_PENDING);

        $phonePrice = new PhonePrice();
        $phonePrice->setMonthlyPremiumPrice(1);

        $premium = $phonePrice->createPremium();
        $policy->setPremium($premium);
        for ($i = 1; $i <= 11; $i++) {
            $payment = new JudoPayment();
            $payment->setAmount(1);
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $policy->addPayment($payment);
        }
        //\Doctrine\Common\Util\Debug::dump($policy, 3);
        $this->assertTrue($policy->isPolicy());
        $this->assertTrue($policy->isFinalMonthlyPayment());
        $this->assertEquals(Salva::FINAL_MONTHLY_TOTAL_COMMISSION, $salva->getTotalCommission($policy));
    }
}
