<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicyIteration;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Classes\Helvetia;
use AppBundle\Classes\SoSure;
use AppBundle\Tests\Create;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the helvetia phone policy does the right things.
 */
class HelvetiaPhonePolicyTest extends TestCase
{
    /**
     * Makes sure that the proRata multiplier is correctly calculated.
     */
    public function testproRataMultiplier()
    {
        $user = Create::user();
        $policy = Create::helvetiaPhonePolicy($user, '2020-01-01', Policy::STATUS_ACTIVE, 12);
        $policy->setEnd(new \DateTime('2020-01-02'));
        $this->assertEquals(1 / 366, $policy->proRataMultiplier());
        $policy->setEnd(new \DateTime('2020-04-12'));
        $this->assertEquals(102 / 366, $policy->proRataMultiplier());
        $policy->setEnd($policy->getStaticEnd());
        $this->assertEquals(1, $policy->proRataMultiplier());
        $policy->setStart(new \DateTime('2019-01-01'));
        $policy->setStaticEnd(new \DateTime('2020-01-01'));
        $policy->setEnd(new \DateTime('2019-01-02'));
        $this->assertEquals(1 / 365, $policy->proRataMultiplier());
        $policy->setEnd(new \DateTime('2019-02-01'));
        $this->assertEquals(31 / 365, $policy->proRataMultiplier());
    }

    /**
     * Makes sure commission is correctly calculated.
     */
    public function testSetCommission()
    {
        $user = Create::user();
        $policy = Create::helvetiaPhonePolicy($user, '2018-01-01', Policy::STATUS_ACTIVE, 12);
        $payment = Create::standardPayment($policy, '2018-01-01', true);
        $policy->setCommission($payment);
        $this->assertEquals(Helvetia::MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());
        $this->assertEquals(
            $payment->getAmount() * 0.2,
            $payment->getCoverholderCommission()
        );
        $this->assertEquals(
            $payment->getAmount() * 0.2 + Helvetia::MONTHLY_BROKER_COMMISSION,
            $payment->getTotalCommission()
        );
    }

    /**
     * Testing that Salva policies renew as Helvetia policies
     */
    public function testSalvaRenewals()
    {
        $user = new User();
        $user->setLocked(false);
        $user->setEnabled(true);
        $phone = new Phone();
        $price = Create::phonePrice('2019-02-02', PhonePrice::STREAM_MONTHLY);
        $phone->addPhonePrice($price);
        $policy = Create::salvaPhonePolicy($user, '2019-01-15', Policy::STATUS_ACTIVE, 12);
        $policy->setImei(ImeiTrait::generateRandomImei());
        $policy->setPhone($phone);
        $user->addPolicy($policy);
        $terms = new policyTerms();
        $newPolicy = $policy->createPendingRenewal($terms, new \DateTime('2020-02-01'));
        $this->assertTrue($newPolicy instanceof HelvetiaPhonePolicy);
        $this->assertFalse($newPolicy instanceof SalvaPhonePolicy);
    }

    /**
     * Tests to make sure that iterations can correctly calculate their periods, and that the total of iteration
     * periods in a policy adds up to the policy's period.
     */
    public function testIterationPeriods()
    {
        $user = Create::user();
        $policy = Create::helvetiaPhonePolicy($user, '2021-01-01 13:12', Policy::STATUS_ACTIVE, 12);
        $a = Create::phonePolicyIteration('2020-01-01 5:34', '2020-02-15 02:43', 1, 1);
        $b = Create::phonePolicyIteration('2020-02-15 19:21', '2020-04-23 19:54', 2, 1);
        $c = Create::phonePolicyIteration('2020-04-23 7:34', '2020-08-01 22:00', 3, 1);
        $d = Create::phonePolicyIteration('2020-08-01 12:31', '2020-12-01 14:41', 4, 1);
        $policy->addPreviousIteration($a);
        $policy->addPreviousIteration($b);
        $policy->addPreviousIteration($c);
        $policy->addPreviousIteration($d);
        $e = $policy->getCurrentIteration();
        $this->assertEquals(45, $a->getPeriod());
        $this->assertEquals(68, $b->getPeriod());
        $this->assertEquals(100, $c->getPeriod());
        $this->assertEquals(122, $d->getPeriod());
        $this->assertEquals(
            $policy->getDaysInPolicyYear(),
            $a->getPeriod() + $b->getPeriod() + $c->getPeriod() + $d->getPeriod() + $e->getPeriod()
        );
    }

    /**
     * Tests that the upgraded monthly price is calculated correctly on an upgrade.
     */
    public function testUpgradedMonthlyPrice()
    {
        // if old premium is 4, new premium is 7, 76 days in old iteration, 3 payments paid
        // outstanding premium is (290 * 7 * 12) / 366 - (3 * 4 - (76 * 4 * 12) / 366) = 64.524
        $user = Create::user();
        $policy = Create::helvetiaPhonePolicy($user, '2020-01-01 13:12', Policy::STATUS_ACTIVE, 12);
        $a = Create::phonePolicyIteration('2020-01-01 5:34', '2020-03-17 02:43', 3, 1);
        $policy->getPremium()->setGwp(3);
        $policy->getPremium()->setIpt(1);
        Create::standardPayment($policy, '2020-01-01', true);
        Create::standardPayment($policy, '2020-02-01', true);
        Create::standardPayment($policy, '2020-03-01', true);
        $policy->getPremium()->setGwp(6);
        $policy->getPremium()->setIpt(1);
        $policy->addPreviousIteration($a);
        $delta = 64.52 - CurrencyTrait::toTwoDp(64.52 / 9) * 9;
        $this->assertEquals(
            CurrencyTrait::toTwoDp(64.52 / 9),
            $policy->getUpgradedStandardMonthlyPrice()
        );
        $this->assertEquals(
            CurrencyTrait::toTwoDp(CurrencyTrait::toTwoDp(64.52 / 9) + $delta),
            $policy->getUpgradedFinalMonthlyPrice()
        );
        $this->assertTrue($delta != 0);
    }

    /**
     * Tests that the upgraded monthly price is calculated correctly when a policy has been upgraded several times.
     */
    public function testMultipleUpgradedMonthlyPrice()
    {
        // if they start with a premium of 15, then after 95 days upgrade to a premium of 5, and then after 80 days
        // upgrade to a premium of 10.6, then they should have 65.83 or so remaining scheduled assuming they paid 15
        // 3 times and then 3 of 5.127.
        $user = Create::user();
        $policy = Create::helvetiaPhonePolicy($user, '2020-01-01 13:12', Policy::STATUS_ACTIVE, 12);
        $policy->getPremium()->setIpt(0);
        $a = Create::phonePolicyIteration('2020-01-01 13:12', '2020-04-05 02:43', 5, 10);
        $b = Create::phonePolicyIteration('2020-04-05', '2020-06-24', 4, 1);
        $policy->getPremium()->setGwp(15);
        Create::standardPayment($policy, '2020-01-01', true);
        Create::standardPayment($policy, '2020-02-01', true);
        Create::standardPayment($policy, '2020-03-01', true);
        $policy->getPremium()->setGwp(5.1275045537341);
        Create::standardPayment($policy, '2020-04-01', true);
        Create::standardPayment($policy, '2020-05-01', true);
        Create::standardPayment($policy, '2020-06-01', true);
        $policy->getPremium()->setGwp(10.6);
        $policy->addPreviousIteration($a);
        $policy->addPreviousIteration($b);
        $this->assertEquals(
            65.83,
            $policy->getUpgradedStandardMonthlyPrice() * 5 + $policy->getUpgradedFinalMonthlyPrice()
        );
    }

    /**
     * Makes sure that if there is no upgrade, the price is exactly unchanged.
     */
    public function testUpgradedPriceSameWithoutUpgrade()
    {
        // yearly premium * 366 / 366 = yearly premium
        $date = new \DateTime('2020-01-15');
        $user = Create::user();
        $policy = Create::helvetiaPhonePolicy($user, $date, Policy::STATUS_ACTIVE, 12);
        $this->assertEquals(
            $policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice(),
            $policy->getUpgradedStandardMonthlyPrice()
        );
        $this->assertEquals(
            $policy->getPremium()->getAdjustedFinalMonthlyPremiumPrice(),
            $policy->getUpgradedFinalMonthlyPrice()
        );
        $this->assertEquals(
            $policy->getPremium()->getAdjustedYearlyPremiumPrice(),
            $policy->getUpgradedYearlyPrice()
        );
    }

    /**
     * Tests that the yearly upgraded price is calculated correctly.
     */
    public function testUpgradedYearlyPriceIncrease()
    {
        // premium used to be 60.66 and now it becomes 124.32 after 205 days.
        // (60.6 * 205) / 366 + (124.32 * 161) / 366 - 60.6 = 28.03
        $user = Create::user();
        $policy = Create::helvetiaPhonePolicy($user, '2020-01-01 13:12', Policy::STATUS_ACTIVE, 1);
        $a = Create::phonePolicyIteration('2020-01-01 5:34', '2020-07-24 02:43', 5, 0.05);
        $policy->getPremium()->setGwp(5);
        $policy->getPremium()->setIpt(0.05);
        Create::standardPayment($policy, '2020-01-01', true);
        $policy->getPremium()->setGwp(10);
        $policy->getPremium()->setIpt(0.36);
        $policy->addPreviousIteration($a);
        $this->assertEquals(28.03, $policy->getUpgradedYearlyPrice());
    }

    /**
     * Tests that the yearly upgraded price is calculated correctly.
     */
    public function testUpgradedYearlyPriceDecrease()
    {
        // premium used to be 123.50 and now it becomes 45.25 after 164 days.
        // (123.5 * 164) / 366 + (45.25 * 202) / 366 - 123.5 = -43.19
        $user = Create::user();
        $policy = Create::helvetiaPhonePolicy($user, '2020-01-01 13:12', Policy::STATUS_ACTIVE, 1);
        $a = Create::phonePolicyIteration('2020-01-01 5:34', '2020-06-13 02:43', 10, 0.29);
        $policy->getPremium()->setGwp(10);
        $policy->getPremium()->setIpt(0.29);
        Create::standardPayment($policy, '2020-01-01', true);
        $policy->getPremium()->setGwp(3);
        $policy->getPremium()->setIpt(0.77);
        $policy->addPreviousIteration($a);
        // system causes rounding errors hence the error margin. Not within the scope of this to fix.
        $this->assertEquals(-43.19, $policy->getUpgradedYearlyPrice(), null, 0.01);
    }
}
