<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PolicyTerms;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Tests\UserClassTrait;
use AppBundle\Classes\Salva;

/**
 * @group functional-nonet
 */
class PhonePolicyTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use UserClassTrait;

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
        $this->assertFalse($policyApi['has_claim']);
        $this->assertFalse($policyApi['has_network_claim']);
        $this->assertEquals(0, count($policyApi['claim_dates']));
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

    public function testHasNetworkClaimedInLast30Days()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->getUser()->setEmail(static::generateEmail('policya', $this));
        $policyB = static::createUserPolicy(true);
        $policyB->getUser()->setEmail(static::generateEmail('policyb', $this));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB->getUser());
        static::$dm->persist($policyA);
        static::$dm->persist($policyB);
        static::$dm->persist($connectionA);
        static::$dm->persist($connectionB);
        static::$dm->flush();
        $this->assertNotNull($policyA->getId());
        $this->assertNotNull($policyB->getId());

        $this->assertFalse($policyA->hasNetworkClaimedInLast30Days());

        $claimA = new Claim();
        $claimA->setRecordedDate(new \DateTime("2016-01-01"));
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $claimA->setClosedDate(new \DateTime("2016-01-01"));
        $policyA->addClaim($claimA);
        $this->assertFalse($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-02-01")));
        $this->assertTrue($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-01-15")));

        $claimB = new Claim();
        $claimB->setRecordedDate(new \DateTime("2016-02-01"));
        $claimB->setStatus(Claim::STATUS_SETTLED);
        $claimB->setClosedDate(new \DateTime("2016-02-01"));
        $policyA->addClaim($claimB);
        $this->assertTrue($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-02-21")));

        $policyAApi = $policyA->toApiArray();
        $this->assertTrue($policyAApi['has_claim']);
        $this->assertFalse($policyAApi['has_network_claim']);
        $this->assertEquals(2, count($policyAApi['claim_dates']));
        $this->assertTrue(in_array((new \DateTime("2016-01-01"))->format(\DateTime::ATOM), $policyAApi['claim_dates']));
        $this->assertTrue(in_array((new \DateTime("2016-02-01"))->format(\DateTime::ATOM), $policyAApi['claim_dates']));

        $policyBApi = $policyB->toApiArray();
        $this->assertFalse($policyBApi['has_claim']);
        $this->assertTrue($policyBApi['has_network_claim']);
        $this->assertTrue(in_array(
            (new \DateTime("2016-01-01"))->format(\DateTime::ATOM),
            $policyBApi['connections'][0]['claim_dates']
        ));
        $this->assertTrue(in_array(
            (new \DateTime("2016-02-01"))->format(\DateTime::ATOM),
            $policyBApi['connections'][0]['claim_dates']
        ));
    }

    public function testHasNetworkClaimedInLast30DaysWithOpenStatus()
    {
        $policyA = static::createUserPolicy(true);
        $policyB = static::createUserPolicy(true);
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $this->assertFalse($policyA->hasNetworkClaimedInLast30Days(null, true));

        $claimA = new Claim();
        $claimA->setRecordedDate(new \DateTime("2016-01-01"));
        $claimA->setStatus(Claim::STATUS_APPROVED);
        $policyA->addClaim($claimA);
        $this->assertFalse($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-02-01")));
        $this->assertFalse($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-02-01"), true));

        $this->assertFalse($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-01-15")));
        $this->assertTrue($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-01-15"), true));

        $claimB = new Claim();
        $claimB->setRecordedDate(new \DateTime("2016-02-01"));
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policyA->addClaim($claimB);
        $this->assertFalse($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-02-21")));
        $this->assertTrue($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-02-21"), true));
    }

    public function testGetRiskNoPolicy()
    {
        $policyA = new PhonePolicy();
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertNull($policyA->getRisk());
    }

    public function testGetRiskPolicyNoConnectionsPre30()
    {
        $user = new User();
        self::addAddress($user);
        $policyA = new PhonePolicy();
        $policyA->init($user, self::getLatestPolicyTerms(static::$dm));
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPhone(self::$phone);
        $this->assertEquals(PhonePolicy::RISK_LEVEL_HIGH, $policyA->getRisk(new \DateTime("2016-01-10")));
    }

    public function testGetRiskPolicyNoConnectionsPost30()
    {
        $user = new User();
        self::addAddress($user);
        $policyA = new PhonePolicy();
        $policyA->init($user, self::getLatestPolicyTerms(static::$dm));
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPhone(self::$phone);
        $this->assertEquals(PhonePolicy::RISK_LEVEL_MEDIUM, $policyA->getRisk(new \DateTime("2016-02-10")));
    }

    public function testGetRiskPolicyConnectionsZeroPot()
    {
        $user = new User();
        self::addAddress($user);
        $policyA = new PhonePolicy();
        $policyA->init($user, self::getLatestPolicyTerms(static::$dm));
        $policyA->create(rand(1, 999999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPhone(self::$phone);
        $policyA->setPotValue(0);

        $connectionA = new Connection();
        $policyA->addConnection($connectionA);

        $this->assertEquals(PhonePolicy::RISK_LEVEL_HIGH, $policyA->getRisk());
    }

    public function testGetRiskPolicyConnectionsNoClaims()
    {
        $policyConnected = static::createUserPolicy(true);
        $policyConnected->setStart(new \DateTime("2016-01-01"));

        $policyClaim = static::createUserPolicy(true);
        $policyClaim->setStart(new \DateTime("2016-01-01"));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyConnected, $policyClaim, 10, 10);
        $policyClaim->updatePotValue();
        $policyConnected->updatePotValue();

        $this->assertEquals(PhonePolicy::RISK_LEVEL_LOW, $policyConnected->getRisk());
    }

    public function testGetRiskPolicyConnectionsClaimedPre30()
    {
        $policyConnected = static::createUserPolicy(true);
        $policyConnected->setStart(new \DateTime("2016-01-01"));

        $policyClaim = static::createUserPolicy(true);
        $policyClaim->setStart(new \DateTime("2016-01-01"));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyConnected, $policyClaim, 10, 10);
        $policyClaim->updatePotValue();
        $policyConnected->updatePotValue();

        for ($i = 1; $i < 6; $i++) {
            $policy = static::createUserPolicy(true);
            $policy->setStart(new \DateTime("2016-01-01"));
            list($connectionA, $connectionB) = $this->createLinkedConnections($policyConnected, $policy, 10, 10);
            $policy->updatePotValue();
            $policyConnected->updatePotValue();
        }

        $claim = new Claim();
        $claim->setRecordedDate(new \DateTime("2016-01-10"));
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policyClaim->addClaim($claim);

        $this->assertEquals(PhonePolicy::RISK_LEVEL_MEDIUM, $policyConnected->getRisk(new \DateTime("2016-01-20")));
    }

    public function testGetRiskPolicyConnectionsClaimedPost30()
    {
        $policyConnected = static::createUserPolicy(true);
        $policyConnected->setStart(new \DateTime("2016-01-01"));

        $policyClaim = static::createUserPolicy(true);
        $policyClaim->setStart(new \DateTime("2016-01-01"));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyConnected, $policyClaim, 10, 10);
        $policyClaim->updatePotValue();
        $policyConnected->updatePotValue();

        for ($i = 1; $i < 6; $i++) {
            $policy = static::createUserPolicy(true);
            $policy->setStart(new \DateTime("2016-01-01"));
            list($connectionA, $connectionB) = $this->createLinkedConnections($policyConnected, $policy, 10, 10);
            $policy->updatePotValue();
            $policyConnected->updatePotValue();
        }

        $claim = new Claim();
        $claim->setRecordedDate(new \DateTime("2016-01-10"));
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policyClaim->addClaim($claim);

        $this->assertEquals(PhonePolicy::RISK_LEVEL_LOW, $policyConnected->getRisk(new \DateTime("2016-02-20")));
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

    protected function createLinkedConnections($policyA, $policyB, $valueA, $valueB)
    {
        $connectionA = new Connection();
        $connectionA->setValue($valueA);
        if ($valueA > 10) {
            $connectionA->setValue(10);
            $connectionA->setPromoValue($valueA - 10);
        }
        $connectionA->setLinkedUser($policyB->getUser());
        $connectionA->setLinkedPolicy($policyB);
        $policyA->addConnection($connectionA);

        $connectionB = new Connection();
        $connectionB->setValue($valueB);
        if ($valueB > 10) {
            $connectionB->setValue(10);
            $connectionB->setPromoValue($valueB - 10);
        }
        $connectionB->setLinkedUser($policyA->getUser());
        $connectionB->setLinkedPolicy($policyA);
        $policyB->addConnection($connectionB);

        return [$connectionA, $connectionB];
    }

    public function testCalculatePotValueOneConnection()
    {
        $policyA = static::createUserPolicy();
        $policyB = static::createUserPolicy();
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $this->assertEquals(10, $policyA->calculatePotValue());
    }

    public function testCalculatePromoPotValueOneConnection()
    {
        $policyA = static::createUserPolicy();
        $policyA->setPromoCode(PhonePolicy::PROMO_LAUNCH);
        $policyA->setPhone(static::$phone);
        $policyB = static::createUserPolicy();
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 15, 10);
        $policyA->setStatus(PhonePolicy::STATUS_PENDING);
        $policyA->setStart(new \DateTime('2016-01-01'));

        $this->assertEquals(15, $policyA->calculatePotValue());
        $this->assertEquals(5, $policyA->calculatePotValue(true));
        $this->assertEquals(10, $policyB->calculatePotValue());
        $policyA->updatePotValue();
        $this->assertEquals(15, $policyA->getPotValue());
        $this->assertEquals(5, $policyA->getPromoPotValue());
    }

    public function testCalculatePotValueOneInitialOnePostCliffConnection()
    {
        $policyA = static::createUserPolicy();
        $policyB = static::createUserPolicy();
        list($connectionInitialA, $connectionInitialB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);
        list($connectionPostCliffA, $connectionPostCliffB) = $this->createLinkedConnections($policyA, $policyB, 2, 2);

        $this->assertEquals(12, $policyA->calculatePotValue());
    }

    public function testCalculatePotValueOneValidNetworkClaimThirtyPot()
    {
        $policy = static::createUserPolicy();

        $linkedPolicies = [];
        for ($i = 1; $i <= 3; $i++) {
            $linkedPolicy = static::createUserPolicy();
            list($connectionA, $connectionB) = $this->createLinkedConnections($policy, $linkedPolicy, 10, 10);
            $linkedPolicies[] = $linkedPolicy;
        }
        $this->assertEquals(30, $policy->calculatePotValue());

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $linkedPolicies[0]->addClaim($claim);
        $this->assertEquals(0, $policy->calculatePotValue());
    }

    public function testCalculatePotValueOneValidNetworkClaimFourtyPot()
    {
        $policy = static::createUserPolicy(true);

        $linkedPolicies = [];
        for ($i = 1; $i <= 4; $i++) {
            $linkedPolicy = static::createUserPolicy(true);
            list($connectionA, $connectionB) = $this->createLinkedConnections($policy, $linkedPolicy, 10, 10);
            $linkedPolicies[] = $linkedPolicy;
        }
        $this->assertEquals(40, $policy->calculatePotValue());

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $linkedPolicies[0]->addClaim($claim);
        $this->assertEquals(10, $policy->calculatePotValue());
    }

    public function testCalculatePotValueOneValidClaimFourtyPot()
    {
        $policy = static::createUserPolicy();

        $linkedPolicies = [];
        for ($i = 1; $i <= 4; $i++) {
            $linkedPolicy = static::createUserPolicy();
            list($connectionA, $connectionB) = $this->createLinkedConnections($policy, $linkedPolicy, 10, 10);
            $linkedPolicies[] = $linkedPolicy;
        }
        $this->assertEquals(40, $policy->calculatePotValue());

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);
        $this->assertEquals(0, $policy->calculatePotValue());
    }

    public function testCalculatePotValueTwoValidNetworkClaimFourtyPot()
    {
        $policy = static::createUserPolicy();

        $linkedPolicies = [];
        for ($i = 1; $i <= 4; $i++) {
            $linkedPolicy = static::createUserPolicy();
            list($connectionA, $connectionB) = $this->createLinkedConnections($policy, $linkedPolicy, 10, 10);
            $linkedPolicies[] = $linkedPolicy;
        }
        $this->assertEquals(40, $policy->calculatePotValue());

        $claimA = new Claim();
        $claimA->setType(Claim::TYPE_LOSS);
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $linkedPolicies[0]->addClaim($claimA);

        $claimB = new Claim();
        $claimB->setType(Claim::TYPE_LOSS);
        $claimB->setStatus(Claim::STATUS_SETTLED);
        $linkedPolicies[1]->addClaim($claimB);

        $this->assertEquals(0, $policy->calculatePotValue());
    }

    public function testCalculatePotValueOneInvalidNetworkClaimFourtyPot()
    {
        $policy = static::createUserPolicy();

        $linkedPolicies = [];
        for ($i = 1; $i <= 4; $i++) {
            $linkedPolicy = static::createUserPolicy();
            list($connectionA, $connectionB) = $this->createLinkedConnections($policy, $linkedPolicy, 10, 10);
            $linkedPolicies[] = $linkedPolicy;
        }
        $this->assertEquals(40, $policy->calculatePotValue());

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_WITHDRAWN);
        $linkedPolicies[0]->addClaim($claim);
        $this->assertEquals(40, $policy->calculatePotValue());
    }

    public function testConnectionValue()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        $policy->setUser($user);
        // Make sure user isn't a prelaunch user
        $user->setCreated(new \DateTime('2017-01-01'));
        $this->assertFalse($user->isPreLaunch());
        $policy->setUser($user);
        $policy->setPhone(static::$phone);
        // Policy status is null
        $this->assertEquals(0, $policy->getConnectionValue());

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        $policy->setStart(new \DateTime('2016-01-01'));

        // Normal connections without PROMO's or launch users
        $this->assertEquals(10, $policy->getConnectionValue(new \DateTime('2016-02-29 23:59:59')));
        $this->assertEquals(2, $policy->getConnectionValue(new \DateTime('2016-03-01')));

        // Launch Promo Policy (first 1000 policies)
        $policy->setPromoCode(PhonePolicy::PROMO_LAUNCH);
        $this->assertEquals(10, $policy->getConnectionValue(new \DateTime('2016-02-29 23:59:59')));
        $this->assertEquals(5, $policy->getPromoConnectionValue(new \DateTime('2016-02-29 23:59:59')));
        $this->assertEquals(2, $policy->getConnectionValue(new \DateTime('2016-03-01')));
        $this->assertEquals(0, $policy->getPromoConnectionValue(new \DateTime('2016-03-01')));

        // PreLaunch User Policy
        $policy->setPromoCode(null);
        $user->setCreated(new \DateTime('2016-01-01'));
        $user->setPreLaunch(true);
        $this->assertTrue($user->isPreLaunch());
        $this->assertEquals(10, $policy->getConnectionValue(new \DateTime('2016-02-29 23:59:59')));
        $this->assertEquals(5, $policy->getPromoConnectionValue(new \DateTime('2016-02-29 23:59:59')));
        $this->assertEquals(2, $policy->getConnectionValue(new \DateTime('2016-03-01')));
        $this->assertEquals(0, $policy->getPromoConnectionValue(new \DateTime('2016-03-01')));
    }

    public function testConnectionValueWithSelfClaim()
    {
        $policy = static::createUserPolicy(true);
        $linkedPolicy = static::createUserPolicy(true);
        list($connectionA, $connectionB) = $this->createLinkedConnections($policy, $linkedPolicy, 10, 10);
        $this->assertEquals(10, $policy->calculatePotValue());

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        $policy->setStart(new \DateTime('2016-01-01'));

        $this->assertEquals(10, $policy->getConnectionValue(new \DateTime('2016-02-29 23:59:59')));

        $claimA = new Claim();
        $claimA->setType(Claim::TYPE_LOSS);
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claimA);

        $this->assertEquals(0, $policy->getConnectionValue(new \DateTime('2016-02-29 23:59:59')));
    }

    public function testConnectionValueWithNetworkClaim()
    {
        $policy = static::createUserPolicy(true);
        $linkedPolicy = static::createUserPolicy(true);
        list($connectionA, $connectionB) = $this->createLinkedConnections($policy, $linkedPolicy, 10, 10);
        $this->assertEquals(10, $policy->calculatePotValue());

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        $policy->setStart(new \DateTime('2016-01-01'));

        $this->assertEquals(10, $policy->getConnectionValue(new \DateTime('2016-02-29 23:59:59')));

        $claimA = new Claim();
        $claimA->setType(Claim::TYPE_LOSS);
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $linkedPolicy->addClaim($claimA);

        $this->assertEquals(2, $policy->getConnectionValue(new \DateTime('2016-02-29 23:59:59')));
    }

    public function testAllowedConnectionValue()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        $policy->setUser($user);
        // Make sure user isn't a prelaunch user
        $user->setCreated(new \DateTime('2017-01-01'));
        $this->assertFalse($user->isPreLaunch());
        $policy->setUser($user);
        $policy->setPhone(static::$phone);
        // Policy status is null
        $this->assertEquals(0, $policy->getAllowedConnectionValue());

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        $policy->setStart(new \DateTime('2016-01-01'));

        // Normal connections without PROMO's or launch users
        $this->assertEquals(10, $policy->getAllowedConnectionValue(new \DateTime('2016-02-29 23:59:59')));
        $this->assertEquals(2, $policy->getAllowedConnectionValue(new \DateTime('2016-03-01')));

        // last policy value should be fractional of the £10
        $policy->setPotValue($policy->getMaxPot() - 2.75);
        $this->assertEquals(2.75, $policy->getAllowedConnectionValue(new \DateTime('2016-02-29 23:59:59')));
    }

    public function testPotFilled()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        $policy->setUser($user);
        // Make sure user isn't a prelaunch user
        $user->setCreated(new \DateTime('2017-01-01'));
        $this->assertFalse($user->isPreLaunch());
        $policy->setPhone(static::$phone);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));

        $this->assertFalse(
            $policy->isPotCompletelyFilled(),
            sprintf("%s =? %s", $policy->getMaxPot(), $policy->getPotValue())
        );
        $policy->setPotValue($policy->getMaxPot());
        $this->assertTrue(
            $policy->isPotCompletelyFilled(),
            sprintf("%s =? %s", $policy->getMaxPot(), $policy->getPotValue())
        );
    }

    public function testConnectionValues()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        $policy->setUser($user);
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));
        $policy->setUser($user);
        $policy->setPhone(static::$phone);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        $policy->setStart(new \DateTime('2016-01-01'));
        $foundHighValue = false;
        $foundLowValue = false;
        $connectionValues = $policy->getConnectionValues();
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
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));

        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setPhone(self::$phone);
        $policy->setPotValue(80);
    }

    /**
     * @expectedException \Exception
     */
    public function testSetPolicyLaunchValueExceeded()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        $policy->setUser($user);
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));
        $policy->setPromoCode(PhonePolicy::PROMO_LAUNCH);

        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setPhone(self::$phone);
        $policy->setPotValue(120);
    }
    
    public function testSetPolicyLaunchValueOk()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();

        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));
        $policy->setPromoCode(PhonePolicy::PROMO_LAUNCH);

        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setPhone(self::$phone);
        $policy->setPotValue(80);
    }

    public function testPolicyEndDate()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, new \DateTime('2016-01-01 16:00'));
        $this->assertEquals(
            new \DateTime('2016-12-31 23:59:59', new \DateTimeZone(Salva::SALVA_TIMEZONE)),
            $policy->getEnd()
        );
    }

    public function testPolicyEndDateBST()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, new \DateTime('2016-07-01 16:00'));
        $this->assertEquals(
            new \DateTime('2017-06-30 23:59:59', new \DateTimeZone(Salva::SALVA_TIMEZONE)),
            $policy->getEnd()
        );
    }

    public function testConnectionCliffDate()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, new \DateTime('2016-04-19 16:00'));
        $this->assertEquals(new \DateTime('2016-06-18 16:00'), $policy->getConnectionCliffDate());
    }

    public function testCurrentIptRate()
    {
        $policy = new PhonePolicy();
        $this->assertEquals(0.095, $policy->getIptRate(new \DateTime('2016-04-01')));
    }

    public function testNewIptRate()
    {
        $policy = new PhonePolicy();
        $this->assertEquals(0.1, $policy->getIptRate(new \DateTime('2016-10-01')));
    }

    public function testHasMonetaryClaimed()
    {
        $policy = new PhonePolicy();
        $this->assertFalse($policy->hasMonetaryClaimed());

        $claimA = new Claim();
        $claimA->setRecordedDate(new \DateTime("2016-01-01"));
        $claimA->setType(Claim::TYPE_LOSS);
        $claimA->setStatus(Claim::STATUS_WITHDRAWN);
        $policy->addClaim($claimA);
        $this->assertFalse($policy->hasMonetaryClaimed());

        $claimB = new Claim();
        $claimB->setRecordedDate(new \DateTime("2016-01-02"));
        $claimB->setType(Claim::TYPE_DAMAGE);
        $claimB->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claimB);
        $this->assertTrue($policy->hasMonetaryClaimed());
    }

    public function testHistoricalMaxPotValue()
    {
        $user = new User();
        self::addAddress($user);

        $policy = new PhonePolicy();
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setPhone(self::$phone);

        $policy->setPotValue(20);
        $this->assertEquals(20, $policy->getHistoricalMaxPotValue());

        $policy->setPotValue(30);
        $this->assertEquals(30, $policy->getHistoricalMaxPotValue());

        $policy->setPotValue(10);
        $this->assertEquals(30, $policy->getHistoricalMaxPotValue());
    }

    public function testUnreplacedConnectionCancelledPolicyInLast30Days()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->getUser()->setEmail(static::generateEmail('replace-a', $this));
        $policyB = static::createUserPolicy(true);
        $policyB->getUser()->setEmail(static::generateEmail('replace-b', $this));
        static::$dm->persist($policyA);
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB);
        static::$dm->persist($policyB->getUser());
        static::$dm->flush();

        list($connectionAB, $connectionBA) = $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $this->assertNull($policyA->getUnreplacedConnectionCancelledPolicyInLast30Days());

        $policyA->cancel(PhonePolicy::CANCELLED_UNPAID);
        static::$dm->flush();

        $this->assertNotNull($policyB->getUnreplacedConnectionCancelledPolicyInLast30Days());
        $connectionB = $policyB->getUnreplacedConnectionCancelledPolicyInLast30Days();
        $this->assertTrue($connectionB->getLinkedPolicy()->isCancelled());
        $this->assertTrue($connectionB->getLinkedPolicy()->hasEndedInLast30Days());

        $this->assertNull($policyB->getUnreplacedConnectionCancelledPolicyInLast30Days(new \DateTime('2016-01-01')));
    }

    public function testCancelPolicy()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->getUser()->setEmail(static::generateEmail('cancel-a', $this));
        $policyB = static::createUserPolicy(true);
        $policyB->getUser()->setEmail(static::generateEmail('cancel-b', $this));
        $policyC = static::createUserPolicy(true);
        $policyC->getUser()->setEmail(static::generateEmail('cancel-c', $this));
        static::$dm->persist($policyA);
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB);
        static::$dm->persist($policyB->getUser());
        static::$dm->persist($policyC);
        static::$dm->persist($policyC->getUser());
        static::$dm->flush();
        list($connectionAB, $connectionBA) = $this->createLinkedConnections($policyA, $policyB, 10, 10);
        list($connectionAC, $connectionCA) = $this->createLinkedConnections($policyA, $policyC, 10, 10);
        list($connectionBC, $connectionCB) = $this->createLinkedConnections($policyB, $policyC, 10, 10);
        $policyA->updatePotValue();
        $policyB->updatePotValue();
        $policyC->updatePotValue();

        $this->assertEquals(20, $policyA->getPotValue());
        $this->assertEquals(20, $policyB->getPotValue());
        $this->assertEquals(20, $policyC->getPotValue());

        $policyA->cancel(PhonePolicy::CANCELLED_USER_REQUESTED);

        $this->assertEquals(PhonePolicy::STATUS_CANCELLED, $policyA->getStatus());
        $this->assertEquals(PhonePolicy::CANCELLED_USER_REQUESTED, $policyA->getCancelledReason());
        $now = new \DateTime();
        $this->assertEquals($now->format('y-M-d'), $policyA->getEnd()->format('y-M-d'));
        $this->assertTrue($policyA->getUser()->isLocked());

        $this->assertEquals(0, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());
        $this->assertEquals(10, $policyC->getPotValue());

        $this->assertEquals(2, count($policyA->getConnections()));
        // All connections for the cancelled policy should be zero
        foreach ($policyA->getConnections() as $networkConnection) {
            $this->assertEquals(0, $networkConnection->getTotalValue());
        }

        $this->assertEquals(2, count($policyB->getConnections()));
        // All connections to the cancelled policy should be zero; other connections should remain at value
        foreach ($policyB->getConnections() as $networkConnection) {
            if ($networkConnection->getLinkedPolicy()->getId() == $policyA->getId()) {
                $this->assertEquals(0, $networkConnection->getTotalValue());
            } else {
                $this->assertGreaterThan(0, $networkConnection->getTotalValue());
            }
        }
    }

    public function testCancelPolicyCancelsScheduledPayments()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);

        for ($i = 0; $i < 11; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $policy->addScheduledPayment($scheduledPayment);
        }
        static::$dm->persist($policy);
        static::$dm->persist($user);
        static::$dm->flush();

        $policy->cancel(PhonePolicy::CANCELLED_USER_REQUESTED);
        foreach ($policy->getScheduledPayments() as $scheduledPayment) {
            $this->assertEquals(ScheduledPayment::STATUS_CANCELLED, $scheduledPayment->getStatus());
        }
    }

    public function testGetPremiumPaidNotPolicy()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);

        $this->assertEquals(0, $policy->getPremiumPaid());
    }

    public function testGetPremiumPaidFailedPayment()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $policy->addPayment($payment);

        $this->assertEquals(0, $policy->getPremiumPaid());
    }

    public function testGetPremiumPaid()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);

        $this->assertEquals(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(), $policy->getPremiumPaid());
    }

    public function testNumberOfInstallmentsNonPolicy()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);

        $this->assertNull($policy->getPremiumInstallmentCount());
    }

    public function testNumberOfInstallmentsNoPayments()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $this->assertNull($policy->getPremiumInstallmentCount());
    }

    public function testNumberOfInstallmentsNoScheduled()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);
        // not using policy service here, so simulate what's done there
        $policy->setPremiumInstallments(1);

        $this->assertEquals(1, $policy->getPremiumInstallmentCount());
    }

    public function testNumberOfInstallments11Scheduled()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);
        // not using policy service here, so simulate what's done there
        $policy->setPremiumInstallments(12);

        for ($i = 0; $i < 11; $i++) {
            $policy->addScheduledPayment(new ScheduledPayment());
        }

        $this->assertEquals(12, $policy->getPremiumInstallmentCount());
    }

    public function testNumberOfInstallments11ScheduledWithRescheduled()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);
        // not using policy service here, so simulate what's done there
        $policy->setPremiumInstallments(12);

        for ($i = 0; $i < 11; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $policy->addScheduledPayment($scheduledPayment);
            $policy->addScheduledPayment($scheduledPayment->reschedule());
        }

        $this->assertEquals(12, $policy->getPremiumInstallmentCount());
        $this->assertEquals(22, count($policy->getScheduledPayments()));
    }

    public function testGetInstallmentAmountMonthly()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);
        // not using policy service here, so simulate what's done there
        $policy->setPremiumInstallments(12);

        for ($i = 0; $i < 11; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $policy->addScheduledPayment($scheduledPayment);
            $policy->addScheduledPayment($scheduledPayment->reschedule());
        }

        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice(), $policy->getPremiumInstallmentPrice());
    }

    public function testGetInstallmentAmountYearly()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);
        // not using policy service here, so simulate what's done there
        $policy->setPremiumInstallments(1);

        for ($i = 0; $i < 11; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $policy->addScheduledPayment($scheduledPayment->reschedule());
        }

        $this->assertEquals($policy->getPremium()->getYearlyPremiumPrice(), $policy->getPremiumInstallmentPrice());
    }

    public function testGetBrokerFeePaidNotPolicy()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);

        $this->assertEquals(0, $policy->getTotalCommissionPaid());
    }

    public function testGetBrokerFeePaidFailedPayment()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $policy->addPayment($payment);

        $this->assertEquals(0, $policy->getTotalCommissionPaid());
    }

    public function testGetBrokerFeePaid()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        for ($i = 0; $i <= 1; $i++) {
            $payment = new JudoPayment();
            $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
            $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $policy->addPayment($payment);
        }

        $this->assertEquals(Salva::MONTHLY_TOTAL_COMMISSION * 2, $policy->getTotalCommissionPaid());
    }

    public function testGetSalvaPolicyNumber()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $this->assertEquals(sprintf('%s/1', $policy->getPolicyNumber()), $policy->getSalvaPolicyNumber());

        $policy->incrementSalvaPolicyNumber(new \DateTime());
        $this->assertEquals(sprintf('%s/2', $policy->getPolicyNumber()), $policy->getSalvaPolicyNumber());
    }

    public function testGetSalvaVersion()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $this->assertNull($policy->getSalvaVersion(new \DateTime("2016-01-01")));

        $policy->incrementSalvaPolicyNumber(new \DateTime("2016-01-03"));
        $policy->incrementSalvaPolicyNumber(new \DateTime("2016-01-04"));
        $policy->incrementSalvaPolicyNumber(new \DateTime("2016-01-05"));
        $this->assertEquals(1, $policy->getSalvaVersion(new \DateTime("2016-01-01")));
        $this->assertEquals(2, $policy->getSalvaVersion(new \DateTime("2016-01-03")));
        $this->assertNull($policy->getSalvaVersion(new \DateTime("2016-02-01")));
    }

    public function testGetRemainingBrokerFeePaid()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $policy->addPayment($payment);

        $policy->incrementSalvaPolicyNumber(new \DateTime("2016-01-03"));

        for ($i = 2; $i < 4; $i ++) {
            $payment = new JudoPayment();
            $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
            $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $payment->setDate(new \DateTime(sprintf('2016-0%d-01', $i)));
            $policy->addPayment($payment);
        }

        $this->assertEquals(Salva::MONTHLY_TOTAL_COMMISSION * 2, $policy->getRemainingTotalCommissionPaid([$payment]));
    }

    public function testGetTotalBrokerFee()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $policy->addPayment($payment);

        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $policy->getTotalBrokerFee());
        $this->assertEquals(0, $policy->getMonthsForCancellationCalc([]));
        $this->assertEquals(0, $policy->getTotalBrokerFee([]));
        $this->assertEquals(
            Salva::YEARLY_TOTAL_COMMISSION,
            $policy->getRemainingTotalBrokerFee([])
        );

        $allPayments = $policy->getPaymentsForSalvaVersions(false);
        $this->assertEquals(76.60, $policy->getRemainingTotalGwp($allPayments));

        $policy->incrementSalvaPolicyNumber(new \DateTime("2016-01-03"));

        for ($i = 2; $i < 4; $i ++) {
            $payment = new JudoPayment();
            $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
            $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $payment->setDate(new \DateTime(sprintf('2016-0%d-01', $i)));
            $policy->addPayment($payment);
        }

        $this->assertEquals(
            Salva::YEARLY_TOTAL_COMMISSION - Salva::MONTHLY_TOTAL_COMMISSION,
            $policy->getRemainingTotalBrokerFee([$payment])
        );
    }

    public function testGetLastSuccessfulPaymentCredit()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $this->assertNull($policy->getLastSuccessfulPaymentCredit());

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-01-01');
        $this->assertEquals($date, $policy->getLastSuccessfulPaymentCredit()->getDate());

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $payment->setDate(new \DateTime('2016-02-01'));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-01-01');
        $this->assertEquals($date, $policy->getLastSuccessfulPaymentCredit()->getDate());

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-02-15'));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-02-15');
        $this->assertEquals($date, $policy->getLastSuccessfulPaymentCredit()->getDate());

        // Neg payment (debit/refund) should be ignored
        $payment = new JudoPayment();
        $payment->setAmount(0 - static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-03-15'));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-02-15');
        $this->assertEquals($date, $policy->getLastSuccessfulPaymentCredit()->getDate());
    }

    /**
     * @expectedException \Exception
     */
    public function testShouldExpirePolicyMissingPayment()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        // Policy doesn't have a payment, so should be expired
        $this->assertTrue($policy->shouldExpirePolicy(new \DateTime("2016-01-01")));
    }

    public function testShouldExpirePolicy()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $policy->addPayment($payment);

        $this->assertFalse($policy->shouldExpirePolicy(new \DateTime("2016-01-01")));
        $this->assertTrue($policy->shouldExpirePolicy(new \DateTime("2016-03-03")));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $payment->setDate(new \DateTime('2016-02-01'));
        $policy->addPayment($payment);

        $this->assertFalse($policy->shouldExpirePolicy(new \DateTime("2016-01-01")));
        $this->assertTrue($policy->shouldExpirePolicy(new \DateTime("2016-03-03")));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-02-08'));
        $policy->addPayment($payment);

        $this->assertFalse($policy->shouldExpirePolicy(new \DateTime("2016-02-09")));
        $this->assertTrue($policy->shouldExpirePolicy(new \DateTime("2016-04-15")));
    }

    public function testCanCancelPolicy()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $this->assertTrue($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-01")));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-15")));
        $this->assertTrue($policy->canCancel(Policy::CANCELLED_USER_REQUESTED, new \DateTime("2016-01-15")));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_BADRISK));
    }

    public function testCanCancelPolicyUnpaid()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_UNPAID));

        $policy->setStatus(Policy::STATUS_UNPAID);
        $this->assertTrue($policy->canCancel(Policy::CANCELLED_UNPAID));
    }

    public function testCanCancelPolicyAlreadyCancelled()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail($this->generateEmail('cancel-already-cancelled', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));
        static::$dm->persist($policy);
        static::$dm->persist($user);
        static::$dm->flush();

        $policy->cancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-02"));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-01")));
    }

    public function testCanCancelPolicyExpired()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setEnd(new \DateTime("2016-12-31 23:59"));
        $policy->setStatus(Policy::STATUS_EXPIRED);
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_USER_REQUESTED, new \DateTime("2016-01-01")));
    }

    public function testIsWithinCooloffPeriod()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $this->assertTrue($policy->isWithinCooloffPeriod(new \DateTime("2016-01-01")));
        $this->assertTrue($policy->isWithinCooloffPeriod(new \DateTime("2016-01-14 23:59:59")));
        $this->assertFalse($policy->isWithinCooloffPeriod(new \DateTime("2016-01-15")));
    }

    public function testActiveSCode()
    {
        $scodeA = new SCode();
        $scodeB = new SCode();
        $scodeC = new SCode();
        $scodeA->setActive(false);
        $policy = new PhonePolicy();
        $policy->addSCode($scodeA);
        $policy->addSCode($scodeB);
        $policy->addSCode($scodeC);

        $this->assertEquals(2, count($policy->getActiveSCodes()));
        foreach ($policy->getActiveSCodes() as $scode) {
            $this->assertTrue(in_array($scode->getCode(), [$scodeB->getCode(), $scodeC->getCode()]));
        }
    }
    

    public function testPolicyActualFraudNoRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(PhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $monthlyPolicy->getRefundAmount());

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(PhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $yearlyPolicy->getRefundAmount());
    }

    public function testPolicySuspectedFraudNoRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(PhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $monthlyPolicy->getRefundAmount());

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(PhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $yearlyPolicy->getRefundAmount());
    }

    public function testPolicyUnpaidNoRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(PhonePolicy::CANCELLED_UNPAID, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $monthlyPolicy->getRefundAmount());

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(PhonePolicy::CANCELLED_UNPAID, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $yearlyPolicy->getRefundAmount());
    }

    public function testPolicyCooloffFullRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(PhonePolicy::CANCELLED_COOLOFF, new \DateTime('2016-01-10'));
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            $monthlyPolicy->getRefundAmount()
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(PhonePolicy::CANCELLED_COOLOFF, new \DateTime('2016-01-10'));
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            $yearlyPolicy->getRefundAmount()
        );
    }

    public function testPolicyUserRequestedRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(PhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-10'));
        $this->assertEquals(0, $monthlyPolicy->getRefundAmount(new \DateTime('2016-02-10')));

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(PhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-10'));
        $this->assertEquals(0, $yearlyPolicy->getRefundAmount(new \DateTime('2016-02-10')));
        /* Waiting on agreemnt with salva
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice() * 9,
            $yearlyPolicy->getRefundAmount(new \DateTime('2016-02-10'))
        );
        */

        $yearlyPolicyWithClaim = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setType(Claim::TYPE_THEFT);
        $yearlyPolicyWithClaim->addClaim($claim);
        $this->assertTrue($yearlyPolicyWithClaim->hasMonetaryClaimed(true));
        $yearlyPolicyWithClaim->cancel(PhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-10'));
        $this->assertEquals(0, $yearlyPolicyWithClaim->getRefundAmount());
    }

    public function testPolicyWreckageRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(PhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-10'));
        $this->assertEquals(0, $monthlyPolicy->getRefundAmount(new \DateTime('2016-02-10')));
        /* waiting on agreement with salva
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            $monthlyPolicy->getRefundAmount(new \DateTime('2016-02-10'))
        );
        */

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(PhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-10'));
        $this->assertEquals(0, $yearlyPolicy->getRefundAmount(new \DateTime('2016-02-10')));
        /* waiting on agreement with salva
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice() * 9,
            $yearlyPolicy->getRefundAmount(new \DateTime('2016-02-10'))
        );
        */
    }

    public function testPolicyDispossessionRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(PhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-10'));
        $this->assertEquals(0, $monthlyPolicy->getRefundAmount(new \DateTime('2016-02-10')));
        /* waiting on agreement with salva
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            $monthlyPolicy->getRefundAmount(new \DateTime('2016-02-10'))
        );
        */

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(PhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-10'));
        $this->assertEquals(0, $yearlyPolicy->getRefundAmount(new \DateTime('2016-02-10')));
        /* waiting on agreement with salva
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice() * 9,
            $yearlyPolicy->getRefundAmount(new \DateTime('2016-02-10'))
        );
        */
    }

    private function createPolicyForCancellation($amount, $commission, $installments)
    {
        $user = new User();
        $user->setEmail(self::generateEmail(sprintf('cancel-policy-%d', rand(1, 9999999)), $this));
        self::$dm->persist($user);
        self::$dm->flush();

        self::addAddress($user);
        self::$dm->flush();

        $policy = new PhonePolicy();
        $policy->setPhone(static::$phone);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, new \DateTime("2016-01-01"));
        $policy->setPremiumInstallments($installments);

        $payment = new JudoPayment();
        $payment->setAmount($amount);
        $payment->setTotalCommission($commission);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $policy->addPayment($payment);
        self::$dm->persist($user);
        self::$dm->persist($policy);
        self::$dm->flush();

        return $policy;
    }

    public function testRemainingMonthsInPolicy()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertEquals(11, $monthlyPolicy->getRemainingMonthsInPolicy(new \DateTime('2016-01-01 01:00')));
        $this->assertEquals(11, $monthlyPolicy->getRemainingMonthsInPolicy(new \DateTime('2016-01-02')));
        $this->assertEquals(10, $monthlyPolicy->getRemainingMonthsInPolicy(new \DateTime('2016-02-01')));
        $this->assertEquals(1, $monthlyPolicy->getRemainingMonthsInPolicy(new \DateTime('2016-11-30')));
        $this->assertEquals(0, $monthlyPolicy->getRemainingMonthsInPolicy(new \DateTime('2016-12-01')));
    }
}
