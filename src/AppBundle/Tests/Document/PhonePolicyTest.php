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

    public function testEmptyPolicyReturnsCorrectApiData()
    {
        $policy = new PhonePolicy();
        $phone = new Phone();
        $phone->init('foo', 'bar', 7.29, 1.50);
        $policy->setPhone($phone);

        $policyApi = $policy->toApiArray();
        $this->assertEquals(0, $policyApi['pot']['connections']);
        $this->assertEquals(0, $policyApi['pot']['value']);
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
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertEquals(PhonePolicy::RISK_HIGH, $policyA->getRisk(new \DateTime("2016-01-10")));
    }

    public function testGetRiskPolicyNoConnectionsPost30()
    {
        $policyA = new PhonePolicy();
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertEquals(PhonePolicy::RISK_MEDIUM, $policyA->getRisk(new \DateTime("2016-02-10")));
    }

    public function testGetRiskPolicyConnectionsZeroPot()
    {
        $policyA = new PhonePolicy();
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPotValue(0);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $this->assertEquals(PhonePolicy::RISK_HIGH, $policyA->getRisk());
    }

    public function testGetRiskPolicyConnectionsNoClaims()
    {
        $policyA = new PhonePolicy();
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPotValue(20);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $this->assertEquals(PhonePolicy::RISK_LOW, $policyA->getRisk());
    }

    public function testGetRiskPolicyConnectionsClaimedPre30()
    {
        $policyA = new PhonePolicy();
        $policyA->create(rand(1, 999999));
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
        $policyA->create(rand(1, 999999));
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

    public function testCalculatePotValueNoConnections()
    {
        $policy = new PhonePolicy();
        $this->assertEquals(0, $policy->calculatePotValue());
    }

    public function testCalculatePotValueOneConnection()
    {
        $policy = new PhonePolicy();
        $connection = new Connection();
        $connection->setValue(10);
        $policy->addConnection($connection);
        $this->assertEquals(10, $policy->calculatePotValue());
    }

    public function testCalculatePotValueOneOldOneNewConnection()
    {
        $policy = new PhonePolicy();
        $connectionOld = new Connection();
        $connectionOld->setValue(10);
        $policy->addConnection($connectionOld);
        $connectionNew = new Connection();
        $connectionNew->setValue(2);
        $policy->addConnection($connectionNew);
        $this->assertEquals(12, $policy->calculatePotValue());
    }

    public function testCalculatePotValueOneValidClaimFiveConnections()
    {
        $policy = new PhonePolicy();
        for ($i = 1; $i <= 5; $i++) {
            $connection = new Connection();
            $connection->setValue(10);
            $policy->addConnection($connection);
        }

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);
        $this->assertEquals(10, $policy->calculatePotValue());
    }

    public function testCalculatePotValueTwoValidClaimsFiveConnections()
    {
        $policy = new PhonePolicy();
        for ($i = 1; $i <= 5; $i++) {
            $connection = new Connection();
            $connection->setValue(10);
            $policy->addConnection($connection);
        }

        for ($i = 1; $i <= 2; $i++) {
            $claim = new Claim();
            $claim->setType(Claim::TYPE_LOSS);
            $policy->addClaim($claim);
        }
        $this->assertEquals(00, $policy->calculatePotValue());
    }

    public function testCalculatePotValueOneInvalidClaimFiveConnections()
    {
        $policy = new PhonePolicy();
        for ($i = 1; $i <= 5; $i++) {
            $connection = new Connection();
            $connection->setValue(10);
            $policy->addConnection($connection);
        }

        $claim = new Claim();
        $claim->setType(Claim::TYPE_WITHDRAWN);
        $policy->addClaim($claim);
        $this->assertEquals(50, $policy->calculatePotValue());
    }
}
