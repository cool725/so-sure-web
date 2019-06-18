<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\Salva;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Exception\InvalidPaymentException;

/**
 * @group unit
 */
class PaymentTest extends \PHPUnit\Framework\TestCase
{
    use CurrencyTrait;
    use DateTrait;

    public function testCalculatePremium()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2016-05-01');
        $premium = $phonePrice->createPremium(null, $date);

        $phonePolicy = new SalvaPhonePolicy();
        $phonePolicy->setPremium($premium);

        $payment = new CheckoutPayment();
        $payment->setAmount(5);
        $payment->setPolicy($phonePolicy);
        $payment->calculateSplit();
        $this->assertEquals(4.57, $this->toTwoDp($payment->getGwp()));
        $this->assertEquals(0.43, $this->toTwoDp($payment->getIpt()));
    }

    public function testTotalCommission()
    {
        $payment = new CheckoutPayment();

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
        $payment = new CheckoutPayment();
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
            $payment = new CheckoutPayment();
            $payment->setAmount(6);
            $policy->addPayment($payment);
            $payment->setCommission();

            // monthly
            $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
            $this->assertEquals(Salva::MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
            $this->assertEquals(Salva::MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());
        }

        $payment = new CheckoutPayment();
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

        $payment = new CheckoutPayment();
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

        $payment = new CheckoutPayment();
        $payment->setAmount(2);
        $policy->addPayment($payment);
        $payment->setCommission();
    }

    public function testTimezone()
    {
        $payments = [];
        $payment1 = new CheckoutPayment();
        $payment1->setDate(new \DateTime('2018-04-01 00:00', new \DateTimeZone('UTC')));
        $payment1->setAmount(1);
        $payments[] = $payment1;

        $payment2 = new CheckoutPayment();
        $payment2->setDate(new \DateTime('2018-04-01 00:00', new \DateTimeZone('Europe/London')));
        $payment2->setAmount(2);
        $payments[] = $payment2;

        $daily = Payment::dailyPayments($payments, false, CheckoutPayment::class, new \DateTimeZone('UTC'));
        $this->assertEquals(1, $daily[1]);

        $daily = Payment::dailyPayments($payments, false, CheckoutPayment::class);
        $this->assertEquals(3, $daily[1]);
    }

    /**
     * Generates testing conditions for testSetCommissionPartialPayment.
     * @return array of sets of arguments to the test.
     */
    public function setCommissionPartialPaymentData()
    {
        return [
            [10, 5, 12, 10, 20],
            [8, 7, 0, 10, 1],
            [5.4, 9.2, 5, 100, 59],
            [3, 3, 0, 1, 0]
        ];
    }

    /**
     * Tests setting the commission for a partial payment.
     * @param float $gwp          is the test premium GWP.
     * @param float $ipt          is the test premium IPT.
     * @param int   $nPayments    is the number of normal payments to make before the payment under test.
     * @param float $finalPayment is the value of the final payment to make.
     * @param int   $delay        is the number of days from the last scheduled payment to the final payment.
     * @dataProvider setCommissionPartialPaymentData
     */
    public function testSetCommissionPartialPayment($gwp, $ipt, $nPayments, $finalPayment, $delay)
    {
        $startDate = new \DateTime();
        $policy = $this->createEligiblePolicy($startDate, $gwp, $ipt, 0.1);
        $date = clone $startDate;
        for ($i = 0; $i < $nPayments; $i++) {
            $this->addPayment($policy, $date);
            $date->add(new \DateInterval("P1M"));
        }
        // Now perform the abnormal fraction.
        $paymentDate = $this->addDays($date, $delay);
        $payment = new CheckoutPayment();
        $payment->setDate($paymentDate);
        $payment->setAmount($finalPayment);
        $policy->addPayment($payment);
        // Make sure that the fractional commission is correct.
        // Should be equal to pro rata commission due.
        $payment->setCommission(true);
        $payment->setSuccess(true);
        $this->assertEquals($policy->getProratedCommission($paymentDate), $policy->getTotalCommissionPaid());
    }

    /**
     * @expectedException \AppBundle\Exception\CommissionException
     */
    public function testSetCommissionRemainderFailsWithFalse()
    {
        $startDate = new \DateTime();
        $policy = $this->createEligiblePolicy($startDate, 5, 1, 0.12);

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice() * 0.5);

        $policy->addPayment($payment);

        $payment->setCommission(false);
    }

    public function testSetCommissionRemainderDoesNotFailWithTrue()
    {
        $startDate = new \DateTime();
        $policy = $this->createEligiblePolicy($startDate, 5, 1, 0.12);

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice() * 0.5);

        $policy->addPayment($payment);

        $payment->setCommission(true);

        $commission = $payment->getTotalCommission();
        $this->assertGreaterThan(0, $commission);
        static::assertLessThan(Salva::MONTHLY_TOTAL_COMMISSION, $commission);
    }

    /**
     * Makes sure that if you try to calculate the commission on a partial refund directly it will throw a commission
     * exception.
     */
    public function testSetCommissionFailsWithPartialRefund()
    {
        $policy = $this->createEligiblePolicy(new \DateTime(), 5, 6, 1);
        $payment = new CheckoutPayment();
        $payment->setAmount(-4.3);
        // Make sure this payment throws an exception when calculating commission is attempted.
        $this->expectException(InvalidPaymentException::class);
        $payment->setCommission(true);
    }

    /**
     * Makes sure that calling setCommission on a payment with no policy throws and invalid payment exception.
     */
    public function testSetComissionWithoutPolicyFails()
    {
        $payment = new CheckoutPayment();
        $payment->setAmount(4.3);
        // make sure an exception is thrown for all inputs in this case.
        $this->expectException(InvalidPaymentException::class);
        $payment->setCommission(false);
        $this->expectException(InvalidPaymentException::class);
        $payment->setCommission(true);
    }

    /**
     * Creates a policy that is set up enough that it can calculate pro rata commission.
     * @param \DateTime $startDate is the date at which the policy starts.
     * @param float     $gwp       is the policy premium's GWP.
     * @param float     $ipt       is the policy premium's IPT.
     * @param float     $iptRate   is the policy premium's IPT rate.
     * @return Policy that has just been created.
     */
    private function createEligiblePolicy(\DateTime $startDate, $gwp, $ipt, $iptRate)
    {
        $nextYear = clone $startDate;
        $nextYear = $nextYear->modify('+1 year');
        $nextYear->modify("-1 day");
        $nextYear->setTime(23, 59, 59);
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($startDate);
        $policy->setEnd($nextYear);
        $policy->setStaticEnd($nextYear);
        $premium = new PhonePremium();
        $premium->setGwp($gwp);
        $premium->setIpt($ipt);
        $premium->setIptRate($iptRate);
        $premium->setIptRate($ipt / 12);
        $policy->setPremium($premium);
        return $policy;
    }

    /**
     * Create a normal premium payment for the given date and add it to the given policy.
     * @param Policy    $policy is the policy to add the payment to and whose premium is used.
     * @param \DateTime $date   is the date at which the payment occurs.
     */
    private function addPayment($policy, \DateTime $date)
    {
        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setDate($date);
        $policy->addPayment($payment);
        $payment->setCommission();
        $payment->setSuccess(true);
    }
}
