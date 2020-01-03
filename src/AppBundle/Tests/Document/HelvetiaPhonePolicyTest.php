<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\ImeiTrait;
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
        $this->assertEquals(Helvetia::MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());
        $this->assertEquals(
            $payment->getAmount() * 0.2 - Helvetia::MONTHLY_BROKER_COMMISSION,
            $payment->getCoverholderCommission()
        );
        $this->assertEquals($payment->getAmount() * 0.2, $payment->getTotalCommission());
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
}
