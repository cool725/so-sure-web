<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Policy;
use AppBundle\Document\HelvetiaPhonePolicy;
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
}
