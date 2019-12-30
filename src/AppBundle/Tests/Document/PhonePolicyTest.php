<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Tests\UserClassTrait;
use AppBundle\Document\CurrencyTrait;

/**
 * Tests the behaviour of the PhonePolicy document.
 * @group unit
 */
class PhonePolicyTest extends \PHPUnit\Framework\TestCase
{
    use DateTrait;

    /**
     * Makes sure that require full premium behaves as expected with a case where it will stop the claim.
     */
    public function testRequireFullPremiumNormal()
    {
        $phone = new Phone();
        $phone->addPhonePrice($this->createPhonePrice(666, new \DateTime("0001-05-19")));
        $phone->setHighlight(true);
        $policy = new HelvetiaPhonePolicy();
        $policy->setPhone($phone);
        /** @var PhonePrice */
        $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY);
        $this->assertNotNull($price);
        $policy->setPremium($price->createPremium());
        $policy->setStart(new \DateTime("2019-05-19"));
        // check some dates.
        $requiredVeryEarly = $policy->fullPremiumToBePaidForClaim(new \DateTime("2018-02-12"), Claim::TYPE_LOSS);
        $requiredEarly = $policy->fullPremiumToBePaidForClaim(new \DateTime("2019-05-20"), Claim::TYPE_THEFT);
        $requiredEarlyish = $policy->fullPremiumToBePaidForClaim(new \DateTime("2019-06-02"), Claim::TYPE_THEFT);
        $requiredLate = $policy->fullPremiumToBePaidForClaim(new \DateTime("2019-06-20"), Claim::TYPE_LOSS);
        $requiredEarlyDamage = $policy->fullPremiumToBePaidForClaim(new \DateTime("2019-05-20"), Claim::TYPE_DAMAGE);
        $requiredEarlyishDamage = $policy->fullPremiumToBePaidForClaim(new \DateTime("2019-06-02"), Claim::TYPE_DAMAGE);
        $requiredLateDamage = $policy->fullPremiumToBePaidForClaim(new \DateTime("2019-06-20"), Claim::TYPE_DAMAGE);
        // make sure they are as expected.
        $this->assertTrue($requiredVeryEarly);
        $this->assertTrue($requiredEarly);
        $this->assertTrue($requiredEarlyish);
        $this->assertFalse($requiredLate);
        $this->assertFalse($requiredEarlyDamage);
        $this->assertFalse($requiredEarlyishDamage);
        $this->assertFalse($requiredLateDamage);
    }

    /**
     * Make sure that if the policy's phone is not a highlighted model we can not worry about needing to pay whole
     * premium.
     */
    public function testRequireFullPremiumNonHighlighted()
    {
        $phone = new Phone();
        $phone->setHighlight(false);
        $phone->addPhonePrice($this->createPhonePrice(666, new \DateTime("0001-05-19")));
        $policy = new HelvetiaPhonePolicy();
        $policy->setPhone($phone);
        /** @var PhonePrice */
        $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY);
        $this->assertNotNull($price);
        $policy->setPremium($price->createPremium());
        $policy->setStart(new \DateTime("2019-05-19"));
        // check some dates.
        $requiredVeryEarly = $policy->fullPremiumToBePaidForClaim(new \DateTime("2018-02-12"), Claim::TYPE_LOSS);
        $requiredEarly = $policy->fullPremiumToBePaidForClaim(new \DateTime("2019-05-20"), Claim::TYPE_THEFT);
        $requiredEarlyish = $policy->fullPremiumToBePaidForClaim(new \DateTime("2019-06-02"), Claim::TYPE_LOSS);
        $requiredLate = $policy->fullPremiumToBePaidForClaim(new \DateTime("2019-06-20"), Claim::TYPE_THEFT);
        // make sure they are as expected.
        $this->assertFalse($requiredVeryEarly);
        $this->assertFalse($requiredEarly);
        $this->assertFalse($requiredEarlyish);
        $this->assertFalse($requiredLate);
    }

    /**
     * Create a phone price.
     * @param float     $amount is the amount of the price.
     * @param \DateTime $start  is the date at which the price comes into effect.
     * @return PhonePrice the new price.
     */
    private function createPhonePrice($amount, $start)
    {
        $price = new PhonePrice();
        $price->setValidFrom($start);
        $price->setGwp($amount);
        return $price;
    }
}
