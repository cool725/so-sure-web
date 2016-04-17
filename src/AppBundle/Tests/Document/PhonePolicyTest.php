<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 */
class PhonePolicyTest extends WebTestCase
{
    protected static $container;
    protected static $dm;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
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
        $policyA->create(rand(1,999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertEquals(PhonePolicy::RISK_HIGH, $policyA->getRisk(new \DateTime("2016-01-10")));
    }

    public function testGetRiskPolicyNoConnectionsPost30()
    {
        $policyA = new PhonePolicy();
        $policyA->create(rand(1,999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertEquals(PhonePolicy::RISK_MEDIUM, $policyA->getRisk(new \DateTime("2016-02-10")));
    }

    public function testGetRiskPolicyConnectionsZeroPot()
    {
        $policyA = new PhonePolicy();
        $policyA->create(rand(1,999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPotValue(0);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $this->assertEquals(PhonePolicy::RISK_HIGH, $policyA->getRisk());
    }

    public function testGetRiskPolicyConnectionsNoClaims()
    {
        $policyA = new PhonePolicy();
        $policyA->create(rand(1,999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPotValue(20);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $this->assertEquals(PhonePolicy::RISK_LOW, $policyA->getRisk());
    }

    public function testGetRiskPolicyConnectionsClaimedPre30()
    {
        $policyA = new PhonePolicy();
        $policyA->create(rand(1,999999));
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
        $policyA->create(rand(1,999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPotValue(20);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $claimA = new Claim();
        $claimA->setDate(new \DateTime("2016-01-10"));
        $policyA->addClaim($claimA);

        $this->assertEquals(PhonePolicy::RISK_LOW, $policyA->getRisk(new \DateTime("2016-02-20")));
    }

    /**
     * @expectedException \MongoDuplicateKeyException
     */
    public function testDuplicatePolicyNumberFails()
    {
        $policyA = new PhonePolicy();
        $policyB = new PhonePolicy();
        $policyA->setPolicyNumber(1);
        $policyB->setPolicyNumber(1);
        self::$dm->persist($policyA);
        self::$dm->persist($policyB);
        self::$dm->flush();
    }
}
