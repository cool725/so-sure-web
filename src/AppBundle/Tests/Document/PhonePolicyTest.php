<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 */
class PhonePolicyTest extends WebTestCase
{
    protected static $container;
    protected static $dm;
    protected static $phone;

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
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
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

    public function testIsPolicyWithin60Days()
    {
        $policyA = new PhonePolicy();
        $policyA->setStart(new \DateTime("2016-01-01"));

        $this->assertTrue($policyA->isPolicyWithin60Days(new \DateTime("2016-01-02")));
        $this->assertTrue($policyA->isPolicyWithin60Days(new \DateTime("2016-02-29 23:59:59")));
        $this->assertFalse($policyA->isPolicyWithin60Days(new \DateTime("2016-03-01")));

        $cliffDate = $policyA->getConnectionCliffDate();
        $beforeCliffDate = clone $cliffDate;
        $beforeCliffDate->sub(new \DateInterval('PT1S'));
        $this->assertTrue($policyA->isPolicyWithin60Days($beforeCliffDate));
        $this->assertFalse($policyA->isPolicyWithin60Days($cliffDate));
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
        $policyA->setUser(new User());
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPhone(self::$phone);
        $policyA->setPotValue(0);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $this->assertEquals(PhonePolicy::RISK_HIGH, $policyA->getRisk());
    }

    public function testGetRiskPolicyConnectionsNoClaims()
    {
        $policyA = new PhonePolicy();
        $policyA->setUser(new User());
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPhone(self::$phone);
        $policyA->setPotValue(20);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $this->assertEquals(PhonePolicy::RISK_LOW, $policyA->getRisk());
    }

    public function testGetRiskPolicyConnectionsClaimedPre30()
    {
        $policyA = new PhonePolicy();
        $policyA->setUser(new User());
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPhone(self::$phone);
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
        $policyA->setUser(new User());
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPhone(self::$phone);
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
        $policy->setUser(new User());
        $connection = new Connection();
        $connection->setValue(10);
        $policy->addConnection($connection);
        $this->assertEquals(10, $policy->calculatePotValue());
    }

    public function testCalculatePotValueOneOldOneNewConnection()
    {
        $policy = new PhonePolicy();
        $policy->setUser(new User());
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
        $policy->setUser(new User());
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
        $policy->setUser(new User());
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
        $policy->setUser(new User());
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

    public function testConnectionValue()
    {
        $policy = new PhonePolicy();
        $user = new User();
        // Make sure user isn't a prelaunch user
        $user->setCreated(new \DateTime('2017-01-01'));
        $this->assertFalse($user->isPreLaunchUser());
        $policy->setUser($user);
        // Policy status is null
        $this->assertEquals(0, $policy->getConnectionValue());

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        $policy->setStart(new \DateTime('2016-01-01'));

        // Normal connections without PROMO's or launch users
        $this->assertEquals(10, $policy->getConnectionValue(new \DateTime('2016-02-29 23:59:59')));
        $this->assertEquals(2, $policy->getConnectionValue(new \DateTime('2016-03-01')));

        // Launch Promo Policy (first 1000 policies)
        $policy->setPromoCode(PhonePolicy::PROMO_LAUNCH);
        $this->assertEquals(15, $policy->getConnectionValue(new \DateTime('2016-02-29 23:59:59')));
        $this->assertEquals(2, $policy->getConnectionValue(new \DateTime('2016-03-01')));

        // PreLaunch User Policy
        $policy->setPromoCode(null);
        $user->setCreated(new \DateTime('2016-01-01'));
        $this->assertTrue($user->isPreLaunchUser());
        $this->assertEquals(15, $policy->getConnectionValue(new \DateTime('2016-02-29 23:59:59')));
        $this->assertEquals(2, $policy->getConnectionValue(new \DateTime('2016-03-01')));
    }

    public function testConnectionValues()
    {
        $policy = new PhonePolicy();
        $user = new User();
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));
        $policy->setUser($user);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        $policy->setStart(new \DateTime('2016-01-01'));
        $foundHighValue = false;
        $foundLowValue = false;
        $connectionValues = $policy->getConnectionValues();
        // print_r($connectionValues);
        foreach ($connectionValues as $connectionValue) {
            if ($connectionValue['value'] == 10) {
                $foundHighValue = true;
            } elseif ($connectionValue['value'] == 2) {
                $foundLowValue = true;
            }
        }

        $this->assertTrue($foundHighValue);
        $this->assertTrue($foundLowValue);
    }

    /**
     * @expectedException \Exception
     */
    public function testSetPolicyValueExceeded()
    {
        $policyA = new PhonePolicy();
        $user = new User();
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));

        $policyA->setUser($user);
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPhone(self::$phone);
        $policyA->setPotValue(80);
    }

    /**
     * @expectedException \Exception
     */
    public function testSetPolicyLaunchValueExceeded()
    {
        $policyA = new PhonePolicy();
        $user = new User();
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));
        $policyA->setPromoCode(PhonePolicy::PROMO_LAUNCH);

        $policyA->setUser($user);
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPhone(self::$phone);
        $policyA->setPotValue(120);
    }
    
    public function testSetPolicyLaunchValueOk()
    {
        $policyA = new PhonePolicy();
        $user = new User();
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));
        $policyA->setPromoCode(PhonePolicy::PROMO_LAUNCH);

        $policyA->setUser($user);
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPhone(self::$phone);
        $policyA->setPotValue(80);
    }

    public function testPolicyEndDate()
    {
        $policy = new PhonePolicy();
        $policy->setUser(new User());
        $policy->create(rand(1, 999999), new \DateTime('2016-01-01 16:00'));
        $this->assertEquals(new \DateTime('2016-12-31 23:59:59'), $policy->getEnd());
    }

    public function testPolicyEndDateBST()
    {
        $policy = new PhonePolicy();
        $policy->setUser(new User());
        $policy->create(rand(1, 999999), new \DateTime('2016-07-01 16:00'));
        $this->assertEquals(new \DateTime('2017-06-30 23:59:59'), $policy->getEnd());
    }

    public function testConnectionCliffDate()
    {
        $policy = new PhonePolicy();
        $policy->setUser(new User());
        $policy->create(rand(1, 999999), new \DateTime('2016-04-19 16:00'));
        $this->assertEquals(new \DateTime('2016-06-18 16:00'), $policy->getConnectionCliffDate());
    }
}
