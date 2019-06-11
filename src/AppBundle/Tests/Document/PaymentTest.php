<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\Salva;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\CurrencyTrait;

/**
 * @group unit
 */
class PaymentTest extends \PHPUnit\Framework\TestCase
{
    use CurrencyTrait;

    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testCalculatePremium()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2016-05-01');
        $premium = $phonePrice->createPremium(null, $date);

        $phonePolicy = new SalvaPhonePolicy();
        $phonePolicy->setPremium($premium);

        $payment = new JudoPayment();
        $payment->setAmount(5);
        $payment->setPolicy($phonePolicy);
        $payment->calculateSplit();
        $this->assertEquals(4.57, $this->toTwoDp($payment->getGwp()));
        $this->assertEquals(0.43, $this->toTwoDp($payment->getIpt()));
    }

    public function testTotalCommission()
    {
        $payment = new JudoPayment();

        // yearly
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::YEARLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::YEARLY_BROKER_COMMISSION, $payment->getBrokerCommission());

        // monthly
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());

        // final month
        $payment->setTotalCommission(Salva::FINAL_MONTHLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::FINAL_MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::FINAL_MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());

        // partial
        $payment->setTotalCommission(0.94);
        $this->assertEquals(0.88, $payment->getCoverholderCommission());
        $this->assertEquals(0.06, $payment->getBrokerCommission());
    }

    /**
     * @expectedException \Exception
     */
    public function testOverwriteSuccess()
    {
        $payment = new JudoPayment();
        $this->assertFalse($payment->hasSuccess());
        $payment->setSuccess(true);
        $this->assertTrue($payment->hasSuccess());
        $payment->setSuccess(false);
    }

    public function testSetCommissionMonthly()
    {
        $policy = new PhonePolicy();

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        for ($i = 0; $i < 11; $i++) {
            $payment = new JudoPayment();
            $payment->setAmount(6);
            $policy->addPayment($payment);
            $payment->setCommission();

            // monthly
            $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
            $this->assertEquals(Salva::MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
            $this->assertEquals(Salva::MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());
        }

        $payment = new JudoPayment();
        $payment->setAmount(6);
        $policy->addPayment($payment);
        $payment->setCommission();

        // final month
        $payment->setTotalCommission(Salva::FINAL_MONTHLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::FINAL_MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::FINAL_MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());
    }

    public function testSetCommissionYearly()
    {
        $policy = new PhonePolicy();

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $payment = new JudoPayment();
        $payment->setAmount(6 * 12);
        $policy->addPayment($payment);
        $payment->setCommission();

        // yearly
        $this->assertEquals(Salva::YEARLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::YEARLY_BROKER_COMMISSION, $payment->getBrokerCommission());
    }

    /**
     * @expectedException \Exception
     */
    public function testSetCommissionUnknown()
    {
        $policy = new PhonePolicy();

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $payment = new JudoPayment();
        $payment->setAmount(2);
        $policy->addPayment($payment);
        $payment->setCommission();
    }

    /**
     * Tests setting the commission for payments that will be fractional and positive or negative.
     */
    public function testSetCommissionFractionsKnownError()
    {
        $amount = -1.47;
        $policy = new PhonePolicy();
        $premium = new PhonePremium();
        $premium->setGwp(8.92);
        $premium->setIpt(1.07);
        $policy->setPremium($premium);
        $previousPayment = new BacsPayment();
        $previousPayment->setAmount(9.99);
        $policy->addPayment($previousPayment);
        $payment = new BacsPayment();
        $policy->addPayment($payment);
        $payment->setAmount($amount);
        $payment->setCommission(true);
        // Assure that the commission is now correctly set.
        var_dump($payment->getTotalCommission());
        $this->assertEquals(
            abs(Salva::MONTHLY_TOTAL_COMMISSION * (-1.47 / 9.99)),
            abs($payment->getTotalCommission()),
            '',
            0.01
        );
    }

    /**
     * Tests setting the commission for payments that will be fractional and positive or negative.
     */
    public function testSetCommissionFractions()
    {
        for ($i = 0; $i < 5000; $i++) {
            $amount = rand(1, 20) / 4 * (rand(0, 1) ? 1 : -1);
            var_dump($amount);
            $proportion = rand(1, 100) / 100;
            $split = rand(0, 100) / 100;
            $policy = new PhonePolicy();
            $premium = new PhonePremium();
            $premium->setGwp(abs($amount) * (1 / $proportion) * $split);
            $premium->setIpt(abs($amount) * (1 / $proportion) * (1 - $split));
            $policy->setPremium($premium);
            $previousPayment = new BacsPayment();
            $previousPayment->setAmount(abs($amount) * (1 / $proportion));
            $policy->addPayment($previousPayment);
            $payment = new BacsPayment();
            $policy->addPayment($payment);
            $payment->setAmount($amount);
            $payment->setCommission(true);
            // Assure that the commission is now correctly set.
            $this->assertEquals(
                Salva::MONTHLY_TOTAL_COMMISSION * $proportion,
                abs($payment->getTotalCommission()),
                '',
                0.01
            );
        }
    }

    public function testTimezone()
    {
        $payments = [];
        $payment1 = new JudoPayment();
        $payment1->setDate(new \DateTime('2018-04-01 00:00', new \DateTimeZone('UTC')));
        $payment1->setAmount(1);
        $payments[] = $payment1;

        $payment2 = new JudoPayment();
        $payment2->setDate(new \DateTime('2018-04-01 00:00', new \DateTimeZone('Europe/London')));
        $payment2->setAmount(2);
        $payments[] = $payment2;

        $daily = Payment::dailyPayments($payments, false, JudoPayment::class, new \DateTimeZone('UTC'));
        $this->assertEquals(1, $daily[1]);

        $daily = Payment::dailyPayments($payments, false, JudoPayment::class);
        $this->assertEquals(3, $daily[1]);
    }
}
