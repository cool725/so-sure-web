<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection;

/**
 * @group unit
 */
class PhonePolicyTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testIsPolicyWithin30Days()
    {
        $policyA = new PhonePolicy();
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertTrue($policyA->isPolicyWithin30Days(new \DateTime("2016-01-02")));
        $this->assertTrue($policyA->isPolicyWithin30Days(new \DateTime("2016-01-29")));
        $this->assertFalse($policyA->isPolicyWithin30Days(new \DateTime("2016-02-01")));
    }

    public function testGetLastestClaim()
    {
        $policyA = new PhonePolicy();
        $this->assertNull($policyA->getLatestClaim());
        
        $claimA = new Claim();
        $claimA->setDate(new \DateTime("2016-01-01"));
        $policyA->addClaim($claimA);
        $this->assertNotNull($policyA->getLatestClaim());
        $this->assertEquals($claimA->getDate(), $policyA->getLatestClaim()->getDate());
        
        $claimB = new Claim();
        $claimB->setDate(new \DateTime("2016-02-01"));
        $policyA->addClaim($claimB);
        $this->assertEquals($claimB->getDate(), $policyA->getLatestClaim()->getDate());

        $claimC = new Claim();
        $claimC->setDate(new \DateTime("2016-01-15"));
        $policyA->addClaim($claimC);
        $this->assertEquals($claimB->getDate(), $policyA->getLatestClaim()->getDate());
    }

    public function testHasClaimedInLast30Days()
    {
        $policyA = new PhonePolicy();
        $this->assertFalse($policyA->hasClaimedInLast30Days());
        
        $claimA = new Claim();
        $claimA->setDate(new \DateTime("2016-01-01"));
        $policyA->addClaim($claimA);
        $this->assertFalse($policyA->hasClaimedInLast30Days(new \DateTime("2016-02-01")));
        $this->assertTrue($policyA->hasClaimedInLast30Days(new \DateTime("2016-01-15")));
        
        $claimB = new Claim();
        $claimB->setDate(new \DateTime("2016-02-01"));
        $policyA->addClaim($claimB);
        $this->assertTrue($policyA->hasClaimedInLast30Days(new \DateTime("2016-02-21")));
    }

    public function testGetRiskNoPolicy()
    {
        $policyA = new PhonePolicy();
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertNull($policyA->getRisk());
    }

    public function testGetRiskPolicyNoConnectionsPre30()
    {
        $policyA = new PhonePolicy();
        $policyA->create(1);
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertEquals(PhonePolicy::RISK_HIGH, $policyA->getRisk(new \DateTime("2016-01-10")));
    }

    public function testGetRiskPolicyNoConnectionsPost30()
    {
        $policyA = new PhonePolicy();
        $policyA->create(1);
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertEquals(PhonePolicy::RISK_MEDIUM, $policyA->getRisk(new \DateTime("2016-02-10")));
    }

    public function testGetRiskPolicyConnectionsZeroPot()
    {
        $policyA = new PhonePolicy();
        $policyA->create(1);
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPotValue(0);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $this->assertEquals(PhonePolicy::RISK_HIGH, $policyA->getRisk());
    }

    public function testGetRiskPolicyConnectionsNoClaims()
    {
        $policyA = new PhonePolicy();
        $policyA->create(1);
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPotValue(20);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $this->assertEquals(PhonePolicy::RISK_LOW, $policyA->getRisk());
    }

    public function testGetRiskPolicyConnectionsClaimedPre30()
    {
        $policyA = new PhonePolicy();
        $policyA->create(1);
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPotValue(20);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $claimA = new Claim();
        $claimA->setDate(new \DateTime("2016-01-10"));
        $policyA->addClaim($claimA);

        $this->assertEquals(PhonePolicy::RISK_MEDIUM, $policyA->getRisk(new \DateTime("2016-01-20")));
    }

    public function testGetRiskPolicyConnectionsClaimedPost30()
    {
        $policyA = new PhonePolicy();
        $policyA->create(1);
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPotValue(20);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $claimA = new Claim();
        $claimA->setDate(new \DateTime("2016-01-10"));
        $policyA->addClaim($claimA);

        $this->assertEquals(PhonePolicy::RISK_LOW, $policyA->getRisk(new \DateTime("2016-02-20")));
    }
}
