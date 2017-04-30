<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\Reward;
use AppBundle\Document\SCode;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\CurrencyTrait;
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
    use CurrencyTrait;

    protected static $container;
    protected static $dm;
    protected static $phone;
    protected static $invitationService;
    protected static $userManager;

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
        self::$invitationService = self::$container->get('app.invitation');
        self::$userManager = self::$container->get('fos_user.user_manager');
    }

    public function setUp()
    {
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
    }

    public function tearDown()
    {
        self::$dm->clear();
    }

    public function testEmptyPolicyReturnsCorrectApiData()
    {
        $policy = new SalvaPhonePolicy();
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
        $policyA = new SalvaPhonePolicy();
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertTrue($policyA->isPolicyWithin30Days(new \DateTime("2016-01-02")));
        $this->assertTrue($policyA->isPolicyWithin30Days(new \DateTime("2016-01-29")));
        $this->assertFalse($policyA->isPolicyWithin30Days(new \DateTime("2016-02-01")));
    }

    public function testIsPolicyWithin60Days()
    {
        $policyA = new SalvaPhonePolicy();
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
        $policyA = new SalvaPhonePolicy();
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertNull($policyA->getRisk());
    }

    public function testGetRiskPolicyNoConnectionsPre30()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('risk-policy-pre-30', $this));
        self::$dm->persist($user);
        self::addAddress($user);
        $policyA = new SalvaPhonePolicy();
        $policyA->init($user, self::getLatestPolicyTerms(static::$dm));
        $policyA->setPhone(self::$phone);
        $policyA->create(rand(1, 999999), null, null, rand(1, 9999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertEquals(SalvaPhonePolicy::RISK_LEVEL_HIGH, $policyA->getRisk(new \DateTime("2016-01-10")));
    }

    public function testGetRiskPolicyNoConnectionsPost30()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('risk-policy-post-30', $this));
        self::$dm->persist($user);
        self::addAddress($user);
        $policyA = new SalvaPhonePolicy();
        $policyA->init($user, self::getLatestPolicyTerms(static::$dm));
        $policyA->setPhone(self::$phone);
        $policyA->create(rand(1, 999999), null, null, rand(1, 9999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $this->assertEquals(SalvaPhonePolicy::RISK_LEVEL_MEDIUM, $policyA->getRisk(new \DateTime("2016-02-10")));
    }

    public function testGetRiskPolicyConnectionsZeroPot()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('risk-policy-no-pot', $this));
        self::$dm->persist($user);
        self::addAddress($user);
        $policyA = new SalvaPhonePolicy();
        $policyA->init($user, self::getLatestPolicyTerms(static::$dm));
        $policyA->setPhone(self::$phone);
        $policyA->create(rand(1, 999999), null, null, rand(1, 9999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPotValue(0);

        $connectionA = new StandardConnection();
        $policyA->addConnection($connectionA);

        $this->assertEquals(SalvaPhonePolicy::RISK_LEVEL_HIGH, $policyA->getRisk());
    }

    public function testGetRiskPolicyPendingCancellation()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('testGetRiskPolicyPendingCancellation', $this));
        self::$dm->persist($user);
        self::addAddress($user);
        $policyA = new SalvaPhonePolicy();
        $policyA->init($user, self::getLatestPolicyTerms(static::$dm));
        $policyA->setPhone(self::$phone);
        $policyA->create(rand(1, 999999), null, null, rand(1, 9999));
        $policyA->setStart(new \DateTime("2016-01-01"));
        $policyA->setPendingCancellation(new \DateTime("2016-02-01"));

        $this->assertEquals(SalvaPhonePolicy::RISK_LEVEL_HIGH, $policyA->getRisk());
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

        $this->assertEquals(SalvaPhonePolicy::RISK_LEVEL_LOW, $policyConnected->getRisk());
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

        $this->assertEquals(
            SalvaPhonePolicy::RISK_LEVEL_MEDIUM,
            $policyConnected->getRisk(new \DateTime("2016-01-20"))
        );
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

        $this->assertEquals(SalvaPhonePolicy::RISK_LEVEL_LOW, $policyConnected->getRisk(new \DateTime("2016-02-20")));
    }

    public function testGetRiskReasonPolicyRewardConnection()
    {
        $reward = $this->createReward(static::generateEmail('testGetRiskPolicyRewardConnection', $this));

        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testGetRiskPolicyRewardConnection-user', $this));
        $policy->setStart(new \DateTime());
        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());

        $connection = static::$invitationService->addReward($policy->getUser(), $reward, 10);
        $this->assertEquals(10, $policy->getPotValue());
        $this->assertEquals(10, $connection->getPromoValue());

        $this->assertEquals(SalvaPhonePolicy::RISK_NOT_CONNECTED_NEW_POLICY, $policy->getRiskReason());
    }

    public function testGetRiskReasonPolicyPromoOnlyConnection()
    {
        $policyConnected = static::createUserPolicy(true);
        $policyConnected->setStart(new \DateTime("2016-01-01"));

        $policyClaim = static::createUserPolicy(true);
        $policyClaim->setStart(new \DateTime("2016-01-01"));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyConnected, $policyClaim, 0, 0);
        $connectionA->setPromoValue(10);
        $connectionB->setPromoValue(10);
        $policyClaim->updatePotValue();
        $policyConnected->updatePotValue();
        $this->assertEquals(10, $policyClaim->getPotValue());
        $this->assertEquals(10, $policyClaim->getPromoPotValue());
        $this->assertEquals(0, $connectionA->getValue());
        $this->assertEquals(10, $connectionA->getPromoValue());

        $this->assertEquals(SalvaPhonePolicy::RISK_CONNECTED_POT_ZERO, $policyConnected->getRiskReason());
    }

    /**
     * @expectedException \MongoDuplicateKeyException
     */
    public function testDuplicatePolicyNumberFails()
    {
        $userA = new User();
        $userA->setEmail(static::generateEmail('duplicate-policy-a', $this));
        self::addAddress($userA);
        $userB = new User();
        $userB->setEmail(static::generateEmail('duplicate-policy-b', $this));
        self::addAddress($userB);
        self::$dm->persist($userA);
        self::$dm->persist($userB);
        self::$dm->flush();

        $policyNumber = rand(1000, 999999);
        $policyA = new SalvaPhonePolicy();
        $policyA->init($userA, self::getLatestPolicyTerms(static::$dm));
        $policyA->setPhone(static::$phone);
        $policyA->create($policyNumber, null, null, rand(1, 9999));
        self::$dm->persist($policyA);
        self::$dm->flush();

        $policyB = new SalvaPhonePolicy();
        $policyB->init($userB, self::getLatestPolicyTerms(static::$dm));
        $policyB->setPhone(static::$phone);
        $policyB->create($policyNumber, null, null, rand(1, 9999));
        self::$dm->persist($policyB);
        self::$dm->flush();
    }

    public function testCalculatePotValueNoConnections()
    {
        $policy = new SalvaPhonePolicy();
        $this->assertEquals(0, $policy->calculatePotValue());
    }

    protected function createLinkedConnections($policyA, $policyB, $valueA, $valueB)
    {
        $connectionA = new StandardConnection();
        $connectionA->setValue($valueA);
        if ($valueA > 10) {
            $connectionA->setValue(10);
            $connectionA->setPromoValue($valueA - 10);
        }
        $connectionA->setLinkedUser($policyB->getUser());
        $connectionA->setLinkedPolicy($policyB);
        $policyA->addConnection($connectionA);

        $connectionB = new StandardConnection();
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
        $policyA->setPromoCode(SalvaPhonePolicy::PROMO_LAUNCH);
        $policyA->setPhone(static::$phone);
        $policyB = static::createUserPolicy();
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 15, 10);
        $policyA->setStatus(SalvaPhonePolicy::STATUS_PENDING);
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
        $user->setEmail(static::generateEmail('connection-value', $this));
        self::$dm->persist($user);
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);
        // Make sure user isn't a prelaunch user
        $user->setCreated(new \DateTime('2017-01-01'));
        $this->assertFalse($user->isPreLaunch());
        $policy->setUser($user);
        $policy->setPhone(static::$phone);
        // Policy status is null
        $this->assertEquals(0, $policy->getConnectionValue());

        $policy->setStatus(SalvaPhonePolicy::STATUS_PENDING);
        $policy->setStart(new \DateTime('2016-01-01'));

        // Normal connections without PROMO's or launch users
        $this->assertEquals(10, $policy->getConnectionValue(new \DateTime('2016-02-29 23:59:59')));
        $this->assertEquals(2, $policy->getConnectionValue(new \DateTime('2016-03-01')));

        // Launch Promo Policy (first 1000 policies)
        $policy->setPromoCode(SalvaPhonePolicy::PROMO_LAUNCH);
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

        $policy->setStatus(SalvaPhonePolicy::STATUS_PENDING);
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

        $policy->setStatus(SalvaPhonePolicy::STATUS_PENDING);
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
        $user->setEmail(static::generateEmail('allowed-connection-value', $this));
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);
        // Make sure user isn't a prelaunch user
        $user->setCreated(new \DateTime('2017-01-01'));
        $this->assertFalse($user->isPreLaunch());
        $policy->setUser($user);
        $policy->setPhone(static::$phone);
        // Policy status is null
        $this->assertEquals(0, $policy->getAllowedConnectionValue());

        $policy->setStatus(SalvaPhonePolicy::STATUS_PENDING);
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
        $user->setEmail(static::generateEmail('pot-filled', $this));
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);
        // Make sure user isn't a prelaunch user
        $user->setCreated(new \DateTime('2017-01-01'));
        $this->assertFalse($user->isPreLaunch());
        $policy->setPhone(static::$phone);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));

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
        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));
        $policy->setUser($user);
        $policy->setPhone(static::$phone);
        $policy->setStatus(SalvaPhonePolicy::STATUS_PENDING);
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
        $policy = new SalvaPhonePolicy();
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));

        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setPotValue(80);
    }

    /**
     * @expectedException \Exception
     */
    public function testSetPolicyLaunchValueExceeded()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));
        $policy->setPromoCode(SalvaPhonePolicy::PROMO_LAUNCH);

        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setPotValue(120);
    }
    
    public function testSetPolicyLaunchValueOk()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();

        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));
        $policy->setPromoCode(SalvaPhonePolicy::PROMO_LAUNCH);

        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setPotValue(80);
    }

    public function testPolicyEndDate()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), null, new \DateTime('2016-01-01 16:00'), rand(1, 9999));
        $this->assertEquals(
            new \DateTime('2016-12-31 23:59:59', new \DateTimeZone(Salva::SALVA_TIMEZONE)),
            $policy->getEnd()
        );
    }

    public function testPolicyEndDateBST()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), null, new \DateTime('2016-07-01 16:00'), rand(1, 9999));
        $this->assertEquals(
            new \DateTime('2017-06-30 23:59:59', new \DateTimeZone(Salva::SALVA_TIMEZONE)),
            $policy->getEnd()
        );
    }

    public function testConnectionCliffDate()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), null, new \DateTime('2016-04-19 16:00'), rand(1, 9999));
        $this->assertEquals(new \DateTime('2016-06-18 16:00'), $policy->getConnectionCliffDate());
    }

    public function testHasMonetaryClaimed()
    {
        $policy = new SalvaPhonePolicy();
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

        $policyB = new SalvaPhonePolicy();
        $this->assertFalse($policyB->hasMonetaryClaimed());
        $claimC = new Claim();
        $claimC->setRecordedDate(new \DateTime("2016-01-02"));
        $claimC->setType(Claim::TYPE_EXTENDED_WARRANTY);
        $claimC->setStatus(Claim::STATUS_SETTLED);
        $policyB->addClaim($claimB);
        $this->assertTrue($policyB->hasMonetaryClaimed());
    }

    public function testHistoricalMaxPotValue()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('historical-maxpot', $this));
        self::addAddress($user);

        $policy = new SalvaPhonePolicy();
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

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

        $policyA->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);
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

        $policyA->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);

        $this->assertEquals(SalvaPhonePolicy::STATUS_CANCELLED, $policyA->getStatus());
        $this->assertEquals(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, $policyA->getCancelledReason());
        $now = new \DateTime();
        $this->assertEquals($now->format('y-M-d'), $policyA->getEnd()->format('y-M-d'));
        $this->assertFalse($policyA->getUser()->isLocked());

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
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(self::generateEmail('testCancelPolicyCancelsScheduledPayments', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        for ($i = 0; $i < 11; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $policy->addScheduledPayment($scheduledPayment);
        }
        static::$dm->persist($policy);
        static::$dm->persist($user);
        static::$dm->flush();

        $policy->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);
        foreach ($policy->getScheduledPayments() as $scheduledPayment) {
            $this->assertEquals(ScheduledPayment::STATUS_CANCELLED, $scheduledPayment->getStatus());
        }
    }

    public function testValidateRefundAmountIsInstallmentPrice()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(self::generateEmail('testValidateRefundAmountIsInstallmentPrice', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        // not using policy service here, so simulate what's done there
        $policy->setPremiumInstallments(12);

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        static::$dm->persist($policy);
        static::$dm->persist($user);
        static::$dm->flush();

        $this->assertTrue($policy->validateRefundAmountIsInstallmentPrice($payment));
        $payment->setAmount($payment->getAmount() + 0.0001);
        $this->assertTrue($policy->validateRefundAmountIsInstallmentPrice($payment));
    }

    public function testGetPremiumPaidFailedPayment()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(self::generateEmail('testGetPremiumPaidFailedPayment', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $this->assertEquals(0, $policy->getPremiumPaid());
    }

    public function testGetPremiumPaid()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $this->assertEquals(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(), $policy->getPremiumPaid());
    }

    public function testNumberOfInstallmentsNonPolicy()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $this->assertNull($policy->getPremiumInstallmentCount());
    }

    public function testNumberOfInstallmentsNoPayments()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $this->assertNull($policy->getPremiumInstallmentCount());
    }

    public function testNumberOfInstallmentsNoScheduled()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);
        // not using policy service here, so simulate what's done there
        $policy->setPremiumInstallments(1);

        $this->assertEquals(1, $policy->getPremiumInstallmentCount());
    }

    public function testNumberOfInstallments11Scheduled()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
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
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
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
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);
        // not using policy service here, so simulate what's done there
        $policy->setPremiumInstallments(12);

        for ($i = 0; $i < 11; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $policy->addScheduledPayment($scheduledPayment);
            $policy->addScheduledPayment($scheduledPayment->reschedule());
        }

        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice(), $policy->getPremiumInstallmentPrice());
        $this->assertEquals($policy->getPremium()->getGwp(), $policy->getPremiumGwpInstallmentPrice());
    }

    public function testGetInstallmentAmountYearly()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('installment-yearly', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);
        // not using policy service here, so simulate what's done there
        $policy->setPremiumInstallments(1);

        for ($i = 0; $i < 11; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $policy->addScheduledPayment($scheduledPayment->reschedule());
        }

        $this->assertEquals($policy->getPremium()->getYearlyPremiumPrice(), $policy->getPremiumInstallmentPrice());
        $this->assertEquals($policy->getPremium()->getYearlyGwp(), $policy->getPremiumGwpInstallmentPrice());
    }

    public function testGetBrokerFeePaidNotPolicy()
    {
        $policy = new SalvaPhonePolicy();
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
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $this->assertEquals(0, $policy->getTotalCommissionPaid());
    }

    public function testGetBrokerFeePaid()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('broker-fee-paid', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        for ($i = 0; $i <= 1; $i++) {
            $payment = new JudoPayment();
            $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
            $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $payment->setReceipt(rand(1, 999999));
            $policy->addPayment($payment);
        }

        $this->assertEquals(Salva::MONTHLY_TOTAL_COMMISSION * 2, $policy->getTotalCommissionPaid());
    }

    public function testGetSalvaPolicyNumber()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('savla-policynumber', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $this->assertEquals(sprintf('%s/1', $policy->getPolicyNumber()), $policy->getSalvaPolicyNumber());

        $policy->incrementSalvaPolicyNumber(new \DateTime());
        $this->assertEquals(sprintf('%s/2', $policy->getPolicyNumber()), $policy->getSalvaPolicyNumber());
    }

    public function testGetSalvaVersion()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('salva-version', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
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
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('remianing-broker-fee-paid', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $payment->setReceipt(rand(1, 999999));
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
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone, new \DateTime("2016-01-01"));

        $user = new User();
        $user->setEmail(static::generateEmail('total-broker-fee', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, new \DateTime("2016-01-01"), rand(1, 9999));

        $payment = new JudoPayment();
        $payment->setAmount(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(new \DateTime("2016-01-01"))
        );
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $policy->getTotalBrokerFee());

        $version = $policy->incrementSalvaPolicyNumber(new \DateTime("2016-01-03"));
        // 3 days (10.72 * 3/366) = 0.06
        $this->assertEquals('0.09', $policy->getTotalBrokerFee($version));
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION - 0.09, $policy->getTotalBrokerFee());
    }

    public function testGetLastSuccessfulPaymentCredit()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('last-successful-payment-credit', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $this->assertNull($policy->getLastSuccessfulPaymentCredit());

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-01-01');
        $this->assertEquals($date, $policy->getLastSuccessfulPaymentCredit()->getDate());

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $payment->setDate(new \DateTime('2016-02-01'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-01-01');
        $this->assertEquals($date, $policy->getLastSuccessfulPaymentCredit()->getDate());

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-02-15'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-02-15');
        $this->assertEquals($date, $policy->getLastSuccessfulPaymentCredit()->getDate());

        // Neg payment (debit/refund) should be ignored
        $payment = new JudoPayment();
        $payment->setAmount(0 - static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-03-15'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-02-15');
        $this->assertEquals($date, $policy->getLastSuccessfulPaymentCredit()->getDate());
    }

    /**
     * @expectedException \Exception
     */
    public function testShouldExpirePolicyMissingPayment()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('expire-policy-missing-payment', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setPremiumInstallments(12);

        // Policy doesn't have a payment, so should be expired
        $this->assertTrue($policy->shouldExpirePolicy(null, new \DateTime("2016-01-01")));
    }

    public function testShouldExpirePolicy()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('expire-policy', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setPremiumInstallments(12);

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $this->assertFalse($policy->shouldExpirePolicy(null, new \DateTime("2016-01-01")));
        $this->assertTrue($policy->shouldExpirePolicy(null, new \DateTime("2016-03-03")));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $payment->setDate(new \DateTime('2016-02-01'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $this->assertFalse($policy->shouldExpirePolicy(null, new \DateTime("2016-01-01")));
        $this->assertTrue($policy->shouldExpirePolicy(null, new \DateTime("2016-03-03")));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-02-08'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $this->assertFalse($policy->shouldExpirePolicy(null, new \DateTime("2016-02-09")));
        $this->assertTrue($policy->shouldExpirePolicy(null, new \DateTime("2016-04-15")));
    }

    public function testCanCancelPolicy()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('can-cancel-policy', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $this->assertTrue($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-01")));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-15")));
        $this->assertTrue($policy->canCancel(Policy::CANCELLED_USER_REQUESTED, new \DateTime("2016-01-15")));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_BADRISK));

        // open claim should disallow any cancellations
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-01")));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_USER_REQUESTED, new \DateTime("2016-01-15")));
    }

    /**
     * @expectedException \Exception
     */
    public function testCancelPolicyDisallowed()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('cancel-policy-disallowed', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-15")));
        $policy->cancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-15"));
    }

    /**
     * @expectedException \Exception
     */
    public function testCancelPolicyOpenClaim()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('cancel-policy-open-claim', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));

        $this->assertTrue($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-05")));

        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $policy->addClaim($claim);

        $this->assertFalse($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-05")));
        $policy->cancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-05"));
    }

    public function testCanCancelPolicyUnpaid()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('can-cancel-policy-unpaid', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_UNPAID));

        $policy->setStatus(Policy::STATUS_UNPAID);
        $this->assertTrue($policy->canCancel(Policy::CANCELLED_UNPAID));
    }

    public function testCanCancelPolicyAlreadyCancelled()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail($this->generateEmail('cancel-already-cancelled', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        static::$dm->persist($policy);
        static::$dm->persist($user);
        static::$dm->flush();

        $policy->cancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-02"));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-01")));
    }

    public function testCanCancelPolicyExpired()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('can-cancel-policy-expired', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setEnd(new \DateTime("2016-12-31 23:59"));
        $policy->setStatus(Policy::STATUS_EXPIRED);
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_USER_REQUESTED, new \DateTime("2016-01-01")));
    }

    public function testIsWithinCooloffPeriod()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('is-within-cooloff', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
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
        $policy = new SalvaPhonePolicy();
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
        $monthlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $monthlyPolicy->getRefundAmount());
        $this->assertEquals(0, $monthlyPolicy->getRefundCommissionAmount());

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $yearlyPolicy->getRefundAmount());
        $this->assertEquals(0, $yearlyPolicy->getRefundCommissionAmount());
    }

    public function testPolicySuspectedFraudNoRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $monthlyPolicy->getRefundAmount());
        $this->assertEquals(0, $monthlyPolicy->getRefundCommissionAmount());

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $yearlyPolicy->getRefundAmount());
        $this->assertEquals(0, $yearlyPolicy->getRefundCommissionAmount());
    }

    public function testPolicyUnpaidNoRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->setStatus(Policy::STATUS_UNPAID);
        $monthlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_UNPAID, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $monthlyPolicy->getRefundAmount());
        $this->assertEquals(0, $monthlyPolicy->getRefundCommissionAmount());

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->setStatus(Policy::STATUS_UNPAID);
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_UNPAID, new \DateTime('2016-02-01'));
        $this->assertEquals(0, $yearlyPolicy->getRefundAmount());
        $this->assertEquals(0, $yearlyPolicy->getRefundCommissionAmount());
    }

    public function testPolicyCooloffFullRefund()
    {
        $cancelDate = new \DateTime('2016-01-10');

        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($cancelDate),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_COOLOFF, $cancelDate);
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($cancelDate),
            $monthlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION,
            $monthlyPolicy->getRefundCommissionAmount(new \DateTime('2016-01-10'))
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice($cancelDate),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_COOLOFF, $cancelDate);
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice($cancelDate),
            $yearlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            Salva::YEARLY_TOTAL_COMMISSION,
            $yearlyPolicy->getRefundCommissionAmount(new \DateTime('2016-01-10'))
        );
    }

    public function testPolicyWithFreeMonthCooloffNoRefund()
    {
        $cancelDate = new \DateTime('2016-01-10');

        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($cancelDate),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        // add refund of the first month
        $this->addPayment(
            $monthlyPolicy,
            0 - static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($cancelDate),
            0 - Salva::MONTHLY_TOTAL_COMMISSION
        );

        $monthlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_COOLOFF, $cancelDate);
        $this->assertEquals(
            0,
            $monthlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            0,
            $monthlyPolicy->getRefundCommissionAmount(new \DateTime('2016-01-10'))
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice($cancelDate),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        // add refund of the first month
        $this->addPayment(
            $yearlyPolicy,
            0 - static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($cancelDate),
            0 - Salva::MONTHLY_TOTAL_COMMISSION
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_COOLOFF, $cancelDate);
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice($cancelDate) -
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($cancelDate),
            $yearlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            Salva::YEARLY_TOTAL_COMMISSION - Salva::MONTHLY_TOTAL_COMMISSION,
            $yearlyPolicy->getRefundCommissionAmount(new \DateTime('2016-01-10'))
        );
    }

    public function testDaysInPolicy()
    {
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );

        $this->assertEquals(1, $policy->getDaysInPolicy(new \DateTime('2016-01-01')));
        $this->assertEquals(1, $policy->getDaysInPolicy(new \DateTime('2016-01-01 15:00')));
        $this->assertEquals(2, $policy->getDaysInPolicy(new \DateTime('2016-01-02')));
        $this->assertEquals(31, $policy->getDaysInPolicy(new \DateTime('2016-01-31')));
        $this->assertEquals(41, $policy->getDaysInPolicy(new \DateTime('2016-02-10')));
    }

    public function testProrataMultiplier()
    {
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );

        $this->assertEquals(0.0027, $this->toFourDp($policy->getProrataMultiplier(new \DateTime('2016-01-01'))));
        $this->assertEquals(0.0055, $this->toFourDp($policy->getProrataMultiplier(new \DateTime('2016-01-02'))));
        $this->assertEquals(0.0847, $this->toFourDp($policy->getProrataMultiplier(new \DateTime('2016-01-31'))));
        $this->assertEquals(0.1120, $this->toFourDp($policy->getProrataMultiplier(new \DateTime('2016-02-10'))));
    }

    public function testProratedRefundAmount()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(new \DateTime('2016-02-10')),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $used = static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(new \DateTime('2016-02-10')) *
            $monthlyPolicy->getProrataMultiplier(new \DateTime('2016-02-10'));
        $paid = static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(new \DateTime('2016-02-10'));
        $this->assertEquals(
            $this->toTwoDp($paid - $used),
            $monthlyPolicy->getProratedRefundAmount(new \DateTime('2016-02-10'))
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(new \DateTime('2016-02-10')),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $used = static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(new \DateTime('2016-02-10')) *
            $yearlyPolicy->getProrataMultiplier(new \DateTime('2016-02-10'));
        $paid = static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(new \DateTime('2016-02-10'));
        $this->assertEquals(
            $this->toTwoDp($paid - $used),
            $yearlyPolicy->getProratedRefundAmount(new \DateTime('2016-02-10'))
        );
    }

    public function testProratedRefundCommissionAmount()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $used = Salva::YEARLY_TOTAL_COMMISSION *
            $monthlyPolicy->getProrataMultiplier(new \DateTime('2016-02-10'));
        $paid = Salva::MONTHLY_TOTAL_COMMISSION;
        $this->assertEquals(
            $this->toTwoDp($paid - $used),
            $monthlyPolicy->getProratedRefundCommissionAmount(new \DateTime('2016-02-10'))
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $used = Salva::YEARLY_TOTAL_COMMISSION *
            $yearlyPolicy->getProrataMultiplier(new \DateTime('2016-02-10'));
        $paid = Salva::YEARLY_TOTAL_COMMISSION;
        $this->assertEquals(
            $this->toTwoDp($paid - $used),
            $yearlyPolicy->getProratedRefundCommissionAmount(new \DateTime('2016-02-10'))
        );
    }

    public function testPolicyUserRequestedRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-10'));
        $this->assertEquals(
            $monthlyPolicy->getProratedRefundAmount(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundAmount(new \DateTime('2016-02-10'))
        );
        $this->assertEquals(
            $monthlyPolicy->getProratedRefundCommissionAmount(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundCommissionAmount(new \DateTime('2016-02-10'))
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-10'));
        $this->assertEquals(
            $yearlyPolicy->getProratedRefundAmount(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundAmount(new \DateTime('2016-02-10'))
        );
        $this->assertEquals(
            $yearlyPolicy->getProratedRefundCommissionAmount(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundCommissionAmount(new \DateTime('2016-02-10'))
        );

        $yearlyPolicyWithClaim = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_THEFT);
        $yearlyPolicyWithClaim->addClaim($claim);
        $this->assertTrue($yearlyPolicyWithClaim->hasMonetaryClaimed(true));
        $yearlyPolicyWithClaim->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-10'));
        $this->assertEquals(0, $yearlyPolicyWithClaim->getRefundAmount());
        $this->assertEquals(0, $yearlyPolicyWithClaim->getRefundCommissionAmount());
    }

    public function testPolicyWreckageRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-10'));
        $this->assertEquals(
            $monthlyPolicy->getProratedRefundAmount(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundAmount(new \DateTime('2016-02-10'))
        );
        $this->assertEquals(
            $monthlyPolicy->getProratedRefundCommissionAmount(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundCommissionAmount(new \DateTime('2016-02-10'))
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-10'));
        $this->assertEquals(
            $yearlyPolicy->getProratedRefundAmount(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundAmount(new \DateTime('2016-02-10'))
        );
        $this->assertEquals(
            $yearlyPolicy->getProratedRefundCommissionAmount(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundCommissionAmount(new \DateTime('2016-02-10'))
        );
    }

    public function testPolicyDispossessionRefund()
    {
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-10'));
        $this->assertEquals(
            $monthlyPolicy->getProratedRefundAmount(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundAmount(new \DateTime('2016-02-10'))
        );
        $this->assertEquals(
            $monthlyPolicy->getProratedRefundCommissionAmount(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundCommissionAmount(new \DateTime('2016-02-10'))
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-10'));
        $this->assertEquals(
            $yearlyPolicy->getProratedRefundAmount(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundAmount(new \DateTime('2016-02-10'))
        );
        $this->assertEquals(
            $yearlyPolicy->getProratedRefundCommissionAmount(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundCommissionAmount(new \DateTime('2016-02-10'))
        );
    }

    private function createPolicyForCancellation($amount, $commission, $installments, $date = null)
    {
        if (!$date) {
            $date = new \DateTime("2016-01-01");
        }
        $user = new User();
        $user->setEmail(self::generateEmail(sprintf('cancel-policy-%d', rand(1, 9999999)), $this));
        self::$dm->persist($user);
        self::$dm->flush();

        self::addAddress($user);
        self::$dm->flush();

        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, $date, rand(1, 9999));
        $policy->setPremiumInstallments($installments);

        $this->addPayment($policy, $amount, $commission);

        self::$dm->persist($user);
        self::$dm->persist($policy);
        self::$dm->flush();

        return $policy;
    }

    private function addPayment($policy, $amount, $commission)
    {
        $payment = new JudoPayment();
        $payment->setAmount($amount);
        $payment->setTotalCommission($commission);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        return $payment;
    }

    public function testFinalMonthlyPayment()
    {
        $date = new \DateTime('2016-01-01');
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertFalse($monthlyPolicy->isFinalMonthlyPayment());

        for ($i = 1; $i <= 10; $i++) {
            $this->addPayment(
                $monthlyPolicy,
                static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
                Salva::MONTHLY_TOTAL_COMMISSION
            );
        }
        $this->assertTrue($monthlyPolicy->isFinalMonthlyPayment());
    }

    public function testOutstandingPremium()
    {
        $date = new \DateTime('2016-01-01');
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date) * 11,
            $monthlyPolicy->getOutstandingPremium()
        );

        for ($i = 1; $i <= 10; $i++) {
            $this->addPayment(
                $monthlyPolicy,
                static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
                Salva::MONTHLY_TOTAL_COMMISSION
            );
        }
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
            $monthlyPolicy->getOutstandingPremium()
        );

        $this->addPayment(
            $monthlyPolicy,
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
            Salva::MONTHLY_TOTAL_COMMISSION
        );
        $this->assertEquals(0, $monthlyPolicy->getOutstandingPremium());
    }

    public function testInitialPayment()
    {
        $date = new \DateTime('2016-01-01');
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertTrue($monthlyPolicy->isInitialPayment());

        for ($i = 1; $i <= 11; $i++) {
            $this->addPayment(
                $monthlyPolicy,
                static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
                Salva::MONTHLY_TOTAL_COMMISSION
            );
            $this->assertFalse($monthlyPolicy->isInitialPayment());
        }
    }

    public function testOutstandingPremiumToDate()
    {
        $date = new \DateTime('2016-01-01');
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertEquals(0, $monthlyPolicy->getOutstandingPremiumToDate($date));
        $this->assertTrue($monthlyPolicy->isValidPolicy(null));

        // needs to be just slightly after 1 month
        $date->add(new \DateInterval('PT1S'));

        for ($i = 1; $i <= 11; $i++) {
            $date->add(new \DateInterval('P1M'));
            $this->assertEquals(
                $monthlyPolicy->getPremium()->getMonthlyPremiumPrice() * $i,
                $monthlyPolicy->getOutstandingPremiumToDate($date)
            );
        }
    }

    public function testTotalExpectedPaidToDate()
    {
        $date = new \DateTime('2016-01-01');
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertTrue($monthlyPolicy->isValidPolicy(null));

        // needs to be just slightly later
        $date->add(new \DateInterval('PT1S'));

        $this->assertEquals(
            $monthlyPolicy->getPremium()->getMonthlyPremiumPrice(),
            $monthlyPolicy->getTotalExpectedPaidToDate($date)
        );

        for ($i = 1; $i <= 11; $i++) {
            $date->add(new \DateInterval('P1M'));
            $this->assertEquals(
                $monthlyPolicy->getPremium()->getMonthlyPremiumPrice() * ($i + 1),
                $monthlyPolicy->getTotalExpectedPaidToDate($date)
            );
        }
    }

    /**
     * @expectedException AppBundle\Exception\InvalidPremiumException
     */
    public function testValidatePremiumException()
    {
        $user = new User();
        $user->setEmail(self::generateEmail('validate-premium-exception', $this));
        self::$dm->persist($user);
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone, new \DateTime('2016-01-01'));
        $policy->validatePremium(false, new \DateTime("2016-10-01"));
    }

    public function testValidatePremiumTenPercent()
    {
        $user = new User();
        $user->setEmail(self::generateEmail('validate-premium', $this));
        self::$dm->persist($user);
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone, new \DateTime('2016-01-01'));
        $premium = $policy->getPremium();
        $policy->validatePremium(true, new \DateTime("2016-10-01"));
        $this->assertNotEquals($premium, $policy->getPremium());
        $this->assertEquals(0.095, $premium->getIptRate());
        $this->assertEquals(0.1, $policy->getPremium()->getIptRate());
    }

    public function testValidatePremiumTwelvePercent()
    {
        $user = new User();
        $user->setEmail(self::generateEmail('testValidatePremiumTwelvePercent', $this));
        self::$dm->persist($user);
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone, new \DateTime('2017-01-01'));
        $premium = $policy->getPremium();
        $policy->validatePremium(true, new \DateTime("2017-06-01"));
        $this->assertNotEquals($premium, $policy->getPremium());
        $this->assertEquals(0.1, $premium->getIptRate());
        $this->assertEquals(0.12, $policy->getPremium()->getIptRate());
    }

    public function testLeadSource()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('lead-source', $this));
        self::$dm->persist($user);
        $user->setLeadSource(Lead::LEAD_SOURCE_SCODE);
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));

        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $this->assertEquals(Lead::LEAD_SOURCE_SCODE, $policy->getLeadSource());
    }

    public function testGetDaysInPolicyYearPreFebLeapYear()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('days-policy-year-pre-feb', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, new \DateTime('2016-02-01'), rand(1, 9999));

        $this->assertEquals(366, $policy->getDaysInPolicyYear());
    }

    public function testGetDaysInPolicyYearPostFebLeapYear()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('days-policy-year-post-feb', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, new \DateTime('2016-03-01'), rand(1, 9999));

        $this->assertEquals(365, $policy->getDaysInPolicyYear());
    }

    public function testGetNextBillingDateMonthly()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPremiumInstallments(12);
        $policy->setStart(new \DateTime('2016-01-15'));
        $this->assertEquals(new \DateTime('2016-02-15'), $policy->getNextBillingDate(new \DateTime('2016-02-14')));
        $this->assertEquals(new \DateTime('2016-03-15'), $policy->getNextBillingDate(new \DateTime('2016-02-16')));

        $policy = new SalvaPhonePolicy();
        $policy->setPremiumInstallments(12);
        $policy->setStart(new \DateTime('2016-03-29'));
        $this->assertEquals(new \DateTime('2016-04-28'), $policy->getNextBillingDate(new \DateTime('2016-04-14')));
        $this->assertEquals(new \DateTime('2016-05-28'), $policy->getNextBillingDate(new \DateTime('2016-04-30')));
    }

    public function testGetNextBillingDateYearly()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPremiumInstallments(1);
        $policy->setStart(new \DateTime('2016-02-15'));
        $this->assertEquals(new \DateTime('2017-02-15'), $policy->getNextBillingDate(new \DateTime('2017-02-14')));
        $this->assertEquals(new \DateTime('2018-02-15'), $policy->getNextBillingDate(new \DateTime('2017-02-16')));
    }

    public function testGetPolicyPrefix()
    {
        $sosureUser = new User();
        $sosureUser->setEmailCanonical('testgetpolicyprefix@so-sure.com');
        $sosurePolicy = new SalvaPhonePolicy();
        $sosurePolicy->setUser($sosureUser);

        $user = new User();
        $user->setEmailCanonical('foo@bar.com');
        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);

        $this->assertEquals('INVALID', $sosurePolicy->getPolicyPrefix('prod'));
        $this->assertNull($policy->getPolicyPrefix('prod'));

        $this->assertEquals('TEST', $sosurePolicy->getPolicyPrefix('test'));
        $this->assertEquals('TEST', $policy->getPolicyPrefix('test'));
    }

    public function testHasPolicyPrefix()
    {
        $sosurePolicy = new SalvaPhonePolicy();
        $sosurePolicy->setPolicyNumber('FOO/123');
        $this->assertTrue($sosurePolicy->hasPolicyPrefix('FOO'));
        $this->assertFalse($sosurePolicy->hasPolicyPrefix('foo'));
        // TODO: Should this be up to /
        $this->assertTrue($sosurePolicy->hasPolicyPrefix('F'));
    }

    public function testPolicyExpirationDate()
    {
        $date = new \DateTime('2016-01-01');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        // starts 1/1/16 + 1 month = 1/2/16 + 30 days = 2/3/16
        $this->assertEquals(new \DateTime('2016-03-02'), $policy->getPolicyExpirationDate());
        $this->assertEquals(1, $policy->getPolicyExpirationDateDays(new \DateTime('2016-03-01')));
        $this->assertEquals(30, $policy->getPolicyExpirationDateDays(new \DateTime('2016-02-01')));

        // add an ontime payment
        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $payment->setDate(new \DateTime('2016-02-01'));
        $policy->addPayment($payment);

        // expected payment 1/2/16 + 1 month = 1/3/16 + 30 days = 31/3/16
        $this->assertEquals(new \DateTime('2016-03-31'), $policy->getPolicyExpirationDate());
    
        // add a late payment
        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $payment->setDate(new \DateTime('2016-03-30'));
        $policy->addPayment($payment);

        // expected payment 1/3/16 + 1 month = 1/4/16 + 30 days = 1/5/16
        $this->assertEquals(new \DateTime('2016-05-01'), $policy->getPolicyExpirationDate());
    }

    public function testPolicyIsPaidToDate()
    {
        $date = new \DateTime('2016-01-01');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertTrue($policy->isPolicyPaidToDate(true, new \DateTime('2016-01-31')));
        $this->assertFalse($policy->isPolicyPaidToDate(true, new \DateTime('2016-02-01 00:01')));

        // add an ontime payment
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $payment->setDate(new \DateTime('2016-02-01'));
        $policy->addPayment($payment);

        $this->assertTrue($policy->isPolicyPaidToDate(true, new \DateTime('2016-02-01 00:01')));
        $this->assertFalse($policy->isPolicyPaidToDate(true, new \DateTime('2016-03-01 00:01')));

        // add a late payment
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $payment->setDate(new \DateTime('2016-03-30'));
        $policy->addPayment($payment);

        // we don't actually check when payment arrives, just that its there...
        $this->assertTrue($policy->isPolicyPaidToDate(true, new \DateTime('2016-03-01')));
        $this->assertFalse($policy->isPolicyPaidToDate(true, new \DateTime('2016-04-01 00:01')));
    }

    public function testPolicyIsPaidToDate28()
    {
        $date = new \DateTime('2016-01-30');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12,
            $date
        );
        $this->assertTrue($policy->isPolicyPaidToDate(true, new \DateTime('2016-01-31 00:01')));
        $this->assertTrue($policy->isPolicyPaidToDate(true, new \DateTime('2016-02-01')));
        $this->assertFalse($policy->isPolicyPaidToDate(true, new \DateTime('2016-03-01')));

        // add an ontime payment
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $payment->setDate(new \DateTime('2016-02-28'));
        $policy->addPayment($payment);

        $this->assertTrue($policy->isPolicyPaidToDate(true, new \DateTime('2016-03-01')));
        $this->assertFalse($policy->isPolicyPaidToDate(true, new \DateTime('2016-04-01')));

        // add a late payment
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $payment->setDate(new \DateTime('2016-04-15'));
        $policy->addPayment($payment);

        // we don't actually check when payment arrives, just that its there...
        $this->assertTrue($policy->isPolicyPaidToDate(true, new \DateTime('2016-04-15 00:01')));
        $this->assertFalse($policy->isPolicyPaidToDate(true, new \DateTime('2016-05-01 00:01')));
    }

    public function testHasCorrectPolicyStatus()
    {
        $policy = new SalvaPhonePolicy();
        $this->assertNull($policy->hasCorrectPolicyStatus());

        $date = new \DateTime('2016-01-01');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice($date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertEquals(SalvaPhonePolicy::STATUS_PENDING, $policy->getStatus());
        $this->assertFalse($policy->hasCorrectPolicyStatus($date));

        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        $this->assertTrue($policy->hasCorrectPolicyStatus($date));
        $this->assertFalse($policy->hasCorrectPolicyStatus(new \DateTime('2016-02-02')));

        $policy->setStatus(SalvaPhonePolicy::STATUS_UNPAID);
        $this->assertFalse($policy->hasCorrectPolicyStatus($date));
        $this->assertTrue($policy->hasCorrectPolicyStatus(new \DateTime('2016-02-02')));

        $ignoredStatuses = [
            SalvaPhonePolicy::STATUS_CANCELLED,
            SalvaPhonePolicy::STATUS_EXPIRED,
            SalvaPhonePolicy::STATUS_MULTIPAY_REJECTED,
            SalvaPhonePolicy::STATUS_MULTIPAY_REQUESTED
        ];
        foreach ($ignoredStatuses as $status) {
            $policy->setStatus($status);
            $this->assertNull($policy->hasCorrectPolicyStatus());
        }
    }

    public function testAddTwoLostTheftClaims()
    {
        $policy = new SalvaPhonePolicy();
        $claim1 = new Claim();
        $claim1->setStatus(Claim::STATUS_APPROVED);
        $claim1->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim1);

        $claim2 = new Claim();
        $claim2->setStatus(Claim::STATUS_SETTLED);
        $claim2->setType(Claim::TYPE_DAMAGE);
        $policy->addClaim($claim2);

        $claim3 = new Claim();
        $claim3->setStatus(Claim::STATUS_APPROVED);
        $claim3->setType(Claim::TYPE_THEFT);
        $policy->addClaim($claim3);
    }

    /**
     * @expectedException \Exception
     */
    public function testAddThreeLostTheftClaims()
    {
        $policy = new SalvaPhonePolicy();
        $claim1 = new Claim();
        $claim1->setStatus(Claim::STATUS_APPROVED);
        $claim1->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim1);

        $claim2 = new Claim();
        $claim2->setStatus(Claim::STATUS_SETTLED);
        $claim2->setType(Claim::TYPE_THEFT);
        $policy->addClaim($claim2);

        $claim3 = new Claim();
        $claim3->setStatus(Claim::STATUS_APPROVED);
        $claim3->setType(Claim::TYPE_THEFT);
        $policy->addClaim($claim3);
    }

    public function testSetPhoneVerified()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhoneVerified(null);
        $this->assertNull($policy->getPhoneVerified());
        $policy->setPhoneVerified(false);
        $this->assertFalse($policy->getPhoneVerified());
        $policy->setPhoneVerified(true);
        $this->assertTrue($policy->getPhoneVerified());
        $policy->setPhoneVerified(false);
        $this->assertTrue($policy->getPhoneVerified());
        $policy->setPhoneVerified(null);
        $this->assertTrue($policy->getPhoneVerified());

        $policy = new SalvaPhonePolicy();
        $policy->setPhoneVerified(true);
        $this->assertTrue($policy->getPhoneVerified());
        $policy->setPhoneVerified(false);
        $this->assertTrue($policy->getPhoneVerified());
    }
}
