<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Claim;
use AppBundle\Document\Form\PicSureStatus;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Tests\UserClassTrait;
use DateTime;
use Symfony\Bridge\PhpUnit\ClockMock;

/**
 * @group unit
 */
class ClaimTest extends \PHPUnit\Framework\TestCase
{
    use UserClassTrait;

    public function testSetStatus()
    {
        $claim = new Claim();
        $claim->setType(Claim::TYPE_WARRANTY);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $this->assertNull($claim->getApprovedDate());
    }

    public function testSetStatusExtended()
    {
        $claim = new Claim();
        $claim->setType(Claim::TYPE_EXTENDED_WARRANTY);
        $claim->setStatus(Claim::STATUS_APPROVED);

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testSetStatusSettled()
    {
        $claim = new Claim();
        $claim->setType(Claim::TYPE_EXTENDED_WARRANTY);
        $claim->setStatus(Claim::STATUS_SETTLED);

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testNeedProofOfPurchase()
    {
        $claim = new Claim();
        $policy = new PhonePolicy();
        $premium = new PhonePremium();
        $premium->setExcess(PolicyTerms::getLowExcess());
        $premium->setPicSureExcess(PolicyTerms::getLowExcess());
        $policy->setPremium($premium);
        $policy->addClaim($claim);

        $claim->setFnolRisk(Policy::RISK_LEVEL_HIGH);
        $this->assertTrue($claim->needProofOfPurchase());

        // both high risk & pic-sure validated required to not require proof of purchase
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $this->assertFalse($claim->needProofOfPurchase());

        // invalid imei should always require proof of purchase
        $policy->setInvalidImei(true);
        $this->assertTrue($claim->needProofOfPurchase());
    }
}
