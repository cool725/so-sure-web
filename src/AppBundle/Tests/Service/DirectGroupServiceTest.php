<?php

namespace AppBundle\Tests\Service;

use AppBundle\Classes\DirectGroupHandlerClaim;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Service\DaviesService;
use AppBundle\Service\DirectGroupService;
use AppBundle\Service\DirectGroupServiceExcel;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use AppBundle\Document\User;
use AppBundle\Document\Charge;
use AppBundle\Document\DateTrait;

/**
 * @group functional-nonet
 * AppBundle\\Tests\\Service\\DirectGroupServiceTest
 */
class DirectGroupServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use DateTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var DirectGroupService */
    protected static $directGroupService;
    protected static $phoneA;
    protected static $phoneB;

    /** @var PolicyTerms */
    protected static $policyTerms;

    /** @var PolicyTerms */
    protected static $nonPicSurePolicyTerms;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var DirectGroupService $directGroupService */
        $directGroupService = self::$container->get('app.directgroup');
        self::$directGroupService = $directGroupService;

        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 7', 'memory' => 64]);
        self::$phoneA = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phoneB = $phoneRepo->findOneBy(['devices' => 'A0001', 'memory' => 64]);

        static::$policyTerms = new PolicyTerms();
        static::$policyTerms->setVersion(PolicyTerms::VERSION_10);

        static::$nonPicSurePolicyTerms = new PolicyTerms();
        static::$nonPicSurePolicyTerms->setVersion(PolicyTerms::VERSION_1);
    }

    public function setUp()
    {
        self::$directGroupService->clearErrors();
        self::$directGroupService->clearWarnings();
        self::$directGroupService->clearFees();
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        /*
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 7', 'memory' => 64]);
        self::$phoneA = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phoneB = $phoneRepo->findOneBy(['devices' => 'A0001', 'memory' => 64]);
        */
    }

    public function tearDown()
    {
    }

    public function testUpdatePolicy()
    {
        $imeiOld = self::generateRandomImei();
        $imeiNew = self::generateRandomImei();

        $policy = new PhonePolicy();
        $policy->setId('1');
        $policy->setImei($imeiOld);
        $policy->setPhone(self::$phoneA);

        $claim = new Claim();
        $policy->addClaim($claim);

        $dgA = new DirectGroupHandlerClaim();

        self::$directGroupService->updatePolicy($claim, $dgA, false);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        $claimB = new Claim();
        $claimB->setReplacementPhone(self::$phoneB);
        $claimB->setReplacementImei($imeiNew);
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claimB);

        $dgB = new DirectGroupHandlerClaim();
        $dgB->replacementMake = 'Apple';
        $dgB->replacementModel = 'iPhone 4';

        self::$directGroupService->updatePolicy($claimB, $dgB, false);
        $this->assertEquals($imeiNew, $policy->getImei());
        $this->assertEquals(self::$phoneB->getId(), $policy->getPhone()->getId());
    }

    public function testUpdatePolicyManyClaims()
    {
        $imeiOriginal = self::generateRandomImei();
        $imeiOld = self::generateRandomImei();
        $imeiNew = self::generateRandomImei();

        $policy = new PhonePolicy();
        $policy->setId('1');
        $policy->setImei($imeiOriginal);
        $policy->setPhone(self::$phoneA);

        $claim = new Claim();
        $claim->setReplacementPhone(self::$phoneA);
        $claim->setReplacementImei($imeiOld);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);

        $davies = new DirectGroupHandlerClaim();

        self::$directGroupService->updatePolicy($claim, $davies, false);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        $claim->setStatus(Claim::STATUS_SETTLED);

        $claimB = new Claim();
        $claimB->setReplacementPhone(self::$phoneB);
        $claimB->setReplacementImei($imeiNew);
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claimB);

        $daviesB = new DirectGroupHandlerClaim();
        $daviesB->replacementMake = 'Apple';
        $daviesB->replacementModel = 'iPhone 4';

        self::$directGroupService->updatePolicy($claimB, $daviesB, false);
        $this->assertEquals($imeiNew, $policy->getImei());
        $this->assertEquals(self::$phoneB->getId(), $policy->getPhone()->getId());

        // Rerunning old settled claim should keep the newer imei
        $this->assertEquals(Claim::STATUS_SETTLED, $claim->getStatus());
        self::$directGroupService->updatePolicy($claim, $davies, false);
        $this->assertEquals($imeiNew, $policy->getImei());
        $this->assertEquals(self::$phoneB->getId(), $policy->getPhone()->getId());
    }

    public function testUpdatePolicyManyOpenClaims()
    {
        $imeiOriginal = self::generateRandomImei();
        $imeiOld = self::generateRandomImei();
        $imeiNew = self::generateRandomImei();

        $policy = new PhonePolicy();
        $policy->setId('1');
        $policy->setImei($imeiOriginal);
        $policy->setPhone(self::$phoneA);

        $claim = new Claim();
        $claim->setReplacementPhone(self::$phoneA);
        $claim->setReplacementImei($imeiOld);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);

        $davies = new DirectGroupHandlerClaim();

        self::$directGroupService->updatePolicy($claim, $davies, false);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        $claimB = new Claim();
        $claimB->setReplacementPhone(self::$phoneB);
        $claimB->setReplacementImei($imeiNew);
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claimB);

        $daviesB = new DirectGroupHandlerClaim();
        $daviesB->replacementMake = 'Apple';
        $daviesB->replacementModel = 'iPhone 4';

        self::$directGroupService->updatePolicy($claimB, $daviesB, true);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        // Rerunning old settled claim should keep the newer imei
        $this->assertEquals(Claim::STATUS_APPROVED, $claim->getStatus());
        self::$directGroupService->updatePolicy($claim, $davies, true);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());
    }

    public function testGetPolicyNumber()
    {
        $davies = new DirectGroupHandlerClaim();
        $davies->policyNumber = 'TEST/2017/12345';
        $this->assertEquals('TEST/2017/12345', $davies->getPolicyNumber());

        $davies->policyNumber = 'number TEST/2017/12345';
        $this->assertEquals('TEST/2017/12345', $davies->getPolicyNumber());

        $davies->policyNumber = 'TEST/2017/1A2345';
        $this->assertNull($davies->getPolicyNumber());
    }

    public function testSaveClaimsClosed()
    {
        $policyOpen = static::createUserPolicy(true);
        $policyOpen->getUser()->setEmail(static::generateEmail('testSaveClaimsClosed-Open', $this));
        $claimOpen = new Claim();
        $claimOpen->setNumber(self::getRandomPolicyNumber('TEST'));
        $policyOpen->addClaim($claimOpen);
        $dgOpen = new DirectGroupHandlerClaim();
        $dgOpen->policyNumber = $policyOpen->getPolicyNumber();
        $dgOpen->claimNumber = $claimOpen->getNumber();
        $dgOpen->status = 'Open';
        $dgOpen->lossDate = new \DateTime('2017-02-01');

        $policyClosed = static::createUserPolicy(true);
        $policyClosed->getUser()->setEmail(static::generateEmail('testSaveClaimsClosed-Closed', $this));
        $claimClosed = new Claim();
        $claimClosed->setNumber(self::getRandomPolicyNumber('TEST'));
        $policyClosed->addClaim($claimClosed);
        $daviesClosed = new DirectGroupHandlerClaim();
        $daviesClosed->policyNumber = $policyClosed->getPolicyNumber();
        $daviesClosed->claimNumber = $claimClosed->getNumber();
        $daviesClosed->status = 'Closed';
        $daviesClosed->lossDate = new \DateTime('2017-01-01');

        self::$directGroupService->saveClaims(1, [$daviesClosed, $daviesClosed]);
        self::$directGroupService->saveClaims(1, [$daviesClosed, $dgOpen]);

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testSaveClaimsDavies()
    {
        $policyOpen = static::createUserPolicy(true);
        $policyOpen->getUser()->setEmail(static::generateEmail('testSaveClaimsDavies', $this));
        $claimOpen = new Claim();
        $claimOpen->setNumber(self::getRandomPolicyNumber('TEST'));
        $claimOpen->setHandlingTeam(Claim::TEAM_DAVIES);
        $policyOpen->addClaim($claimOpen);
        static::$dm->persist($policyOpen->getUser());
        static::$dm->persist($policyOpen);
        static::$dm->persist($claimOpen);
        static::$dm->flush();

        $dgOpen = new DirectGroupHandlerClaim();
        $dgOpen->policyNumber = $policyOpen->getPolicyNumber();
        $dgOpen->claimNumber = $claimOpen->getNumber();
        $dgOpen->status = 'Open';
        $dgOpen->lossDate = new \DateTime('2017-02-01');
        static::$dm->flush();

        $this->assertFalse(self::$directGroupService->saveClaim($dgOpen, false));
        $this->insureSoSureActionExists('/Skipping direct group import/');
    }

    public function testSaveClaimsNoHandlingTeam()
    {
        $policyOpen = static::createUserPolicy(true);
        $policyOpen->getUser()->setEmail(static::generateEmail('testSaveClaimsNoHandlingTeam', $this));
        $claimOpen = new Claim();
        $claimOpen->setNumber(self::getRandomPolicyNumber('TEST'));
        $policyOpen->addClaim($claimOpen);
        $dgOpen = new DirectGroupHandlerClaim();
        $dgOpen->policyNumber = $policyOpen->getPolicyNumber();
        $dgOpen->claimNumber = $claimOpen->getNumber();
        $dgOpen->status = 'Open';
        $dgOpen->lossDate = new \DateTime('2017-02-01');
        static::$dm->persist($policyOpen->getUser());
        static::$dm->persist($policyOpen);
        static::$dm->persist($claimOpen);
        static::$dm->flush();

        $this->assertFalse(self::$directGroupService->saveClaim($dgOpen, false));
        $this->insureSoSureActionExists('/Skipping direct group import/');
    }

    public function testSaveClaimsOpen()
    {
        $policyOpen = static::createUserPolicy(true);
        $policyOpen->getUser()->setEmail(static::generateEmail('testSaveClaimsOpen-Open', $this));
        $claimOpen = new Claim();
        $claimOpen->setNumber(self::getRandomPolicyNumber('TEST'));
        $policyOpen->addClaim($claimOpen);
        $dgOpen = new DirectGroupHandlerClaim();
        $dgOpen->policyNumber = $policyOpen->getPolicyNumber();
        $dgOpen->claimNumber = $claimOpen->getNumber();
        $dgOpen->status = 'Open';
        $dgOpen->lossDate = new \DateTime('2017-02-01');

        self::$directGroupService->clearErrors();

        $this->assertEquals(0, count(self::$directGroupService->getWarnings()));
        self::$directGroupService->saveClaims(1, [$dgOpen, $dgOpen]);
        $this->assertEquals(1, count(self::$directGroupService->getWarnings()));

        $this->insureWarningExists('/multiple open claims against policy/');
    }

    public function testSaveClaimsOpenDavies()
    {
        $verifyTest = false;

        $policyOpen = static::createUserPolicy(true);
        $policyOpen->getUser()->setEmail(static::generateEmail('testSaveClaimsOpenDavies', $this));
        $initialImei = $policyOpen->getImei();
        $claimOpen = new Claim();
        $claimOpen->setType(Claim::TYPE_LOSS);
        $claimOpen->setStatus(Claim::STATUS_APPROVED);
        $claimOpen->setNumber(self::getRandomPolicyNumber('TEST'));
        $claimOpen->setHandlingTeam(Claim::TEAM_DAVIES);

        if (!$verifyTest) {
            $policyOpen->addClaim($claimOpen);
        } else {
            $claimOpen->setExpectedExcess(PolicyTerms::getHighExcess());
        }

        $claimOpen2 = new Claim();
        $claimOpen2->setType(Claim::TYPE_LOSS);
        $claimOpen2->setNumber(self::getRandomPolicyNumber('TEST'));
        $claimOpen2->setStatus(Claim::STATUS_APPROVED);
        $claimOpen2->setReplacementImei(static::generateRandomImei());
        $claimOpen2->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policyOpen->addClaim($claimOpen2);

        static::$dm->persist($policyOpen->getUser());
        static::$dm->persist($policyOpen);
        static::$dm->persist($claimOpen);
        static::$dm->persist($claimOpen2);
        static::$dm->flush();
        self::$directGroupService->clearErrors();
        self::$directGroupService->clearWarnings();
        self::$directGroupService->clearSoSureActions();

        $this->assertNotNull($policyOpen->getCurrentExcess());
        $this->assertNotNull($claimOpen->getExpectedExcess());
        $this->assertNotNull($claimOpen2->getExpectedExcess());

        $now = \DateTime::createFromFormat('U', time());
        $dgOpen = new DirectGroupHandlerClaim();
        $dgOpen->policyNumber = $policyOpen->getPolicyNumber();
        $dgOpen->claimNumber = $claimOpen2->getNumber();
        $dgOpen->insuredName = $policyOpen->getUser()->getName();
        $dgOpen->status = 'Open';
        $dgOpen->lossDate = new \DateTime('2017-02-01');
        $dgOpen->replacementImei = static::generateRandomImei();
        $dgOpen->replacementReceivedDate = $now;
        $dgOpen->replacementMake = 'foo';
        $dgOpen->replacementModel = 'bar';
        $dgOpen->phoneReplacementCost = 100;
        $dgOpen->totalIncurred = 100;
        $dgOpen->initialSuspicion = false;
        $dgOpen->finalSuspicion = false;
        $dgOpen->lossDescription = 'foo bar';
        $dgOpen->lossType = DirectGroupHandlerClaim::TYPE_LOSS;

        $this->assertEquals(0, count(self::$directGroupService->getWarnings()));
        self::$directGroupService->saveClaims(1, [$dgOpen]);
        //print_r(self::$directGroupService->getErrors());
        //print_r(self::$directGroupService->getWarnings());
        $this->assertEquals(0, count(self::$directGroupService->getErrors()));
        $this->assertEquals(0, count(self::$directGroupService->getWarnings()));
        $this->assertEquals(0, count(self::$directGroupService->getSoSureActions()));

        $updatedPolicy = $this->assertPolicyExists(static::$container, $policyOpen);

        if ($verifyTest) {
            $this->assertEquals($dgOpen->replacementImei, $updatedPolicy->getImei());
        } else {
            $this->assertEquals($initialImei, $updatedPolicy->getImei());
        }
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Invalid replacement imei invalid
     */
    public function testValidateClaimInvalidImei()
    {
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId('1');
        $policy->setPhone(self::getRandomPhone(self::$dm));

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_SETTLED);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        $directGroupClaim->replacementImei = 'invalid';
        $directGroupClaim->finalSuspicion = null;
        $directGroupClaim->initialSuspicion = null;
        $directGroupClaim->finalSuspicion = null;
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
    }

    public function testMissingLossDescription()
    {
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(static::getRandomPolicyNumber('TEST'));
        $policy->setPolicyTerms(static::$policyTerms);
        $policy->setPhone(self::getRandomPhone(self::$dm));

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        $directGroupClaim->finalSuspicion = null;
        $directGroupClaim->initialSuspicion = null;
        $directGroupClaim->finalSuspicion = null;
        $directGroupClaim->lossDescription = '1234';
        self::$directGroupService->clearWarnings();
        $this->assertEquals(0, count(self::$directGroupService->getWarnings()));
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->assertEquals(1, count(self::$directGroupService->getWarnings()));
        $this->insureWarningExists('/detailed loss description/');
    }

    public function testUpdateClaimNoInitialFlag()
    {

        $user = new User();
        $user->setFirstName('Marko');
        $user->setLastName('Marulic');
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(static::getRandomPolicyNumber('TEST'));
        $policy->setPolicyTerms(static::$policyTerms);
        $policy->setUser($user);
        $policy->setPhone(self::getRandomPhone(self::$dm));

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->insuredName = 'Marko Marulic';
        $directGroupClaim->initialSuspicion = null;
        $directGroupClaim->finalSuspicion = null;

        self::$directGroupService->clearWarnings();
        $this->assertEquals(0, count(self::$directGroupService->getWarnings()));
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->assertEquals(1, count(self::$directGroupService->getWarnings()));
        $this->insureWarningExists('/initialSuspicion/');
    }

    public function testUpdateClaimValidDaviesInitialFlag()
    {
        $user = new User();
        $user->setFirstName('Marko');
        $user->setLastName('Marulic');
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(static::getRandomPolicyNumber('TEST'));
        $policy->setPolicyTerms(static::$policyTerms);
        $policy->setUser($user);
        $policy->setPhone(self::getRandomPhone(self::$dm));

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->insuredName = 'Marko Marulic';
        $directGroupClaim->lossDescription = 'min length';
        $directGroupClaim->initialSuspicion = false;
        $directGroupClaim->finalSuspicion = null;

        self::$directGroupService->clearWarnings();
        $this->assertEquals(0, count(self::$directGroupService->getWarnings()));
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->assertEquals(0, count(self::$directGroupService->getWarnings()));
    }

    public function testUpdateClaimValidDaviesFinalFlag()
    {
        $user = new User();
        $user->setFirstName('Marko');
        $user->setLastName('Marulic');
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(static::getRandomPolicyNumber('TEST'));
        $policy->setPolicyTerms(static::$policyTerms);
        $policy->setUser($user);
        $policy->setPhone(self::getRandomPhone(self::$dm));

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        $directGroupClaim->insuredName = 'Marko Marulic';
        $directGroupClaim->lossDescription = 'min length';
        $directGroupClaim->initialSuspicion = false;
        $directGroupClaim->finalSuspicion = false;

        self::$directGroupService->clearWarnings();
        $this->assertEquals(0, count(self::$directGroupService->getWarnings()));
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->assertEquals(0, count(self::$directGroupService->getWarnings()));
    }

    public function testUpdateClaimValidDaviesInitialFlagMissing()
    {
        $user = new User();
        $user->setFirstName('Marko');
        $user->setLastName('Marulic');
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(static::getRandomPolicyNumber('TEST'));
        $policy->setPolicyTerms(static::$policyTerms);
        $policy->setUser($user);
        $policy->setPhone(self::getRandomPhone(self::$dm));

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->insuredName = 'Marko Marulic';
        $directGroupClaim->initialSuspicion = null;
        $directGroupClaim->finalSuspicion = null;

        self::$directGroupService->clearWarnings();
        $this->assertEquals(0, count(self::$directGroupService->getWarnings()));
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->assertEquals(1, count(self::$directGroupService->getWarnings()));
        $this->insureWarningExists('/initialSuspicion/');
    }
    
    private function getRandomClaimNumber()
    {
        return sprintf('%6d', rand(1, 999999));
    }

    public function testSaveClaimsOpenClosed()
    {
        $dgOpen = new DirectGroupHandlerClaim();
        $dgOpen->policyNumber = self::getRandomPolicyNumber('TEST');
        $dgOpen->claimNumber = 'a';
        $dgOpen->status = 'Open';
        $dgOpen->lossDate = new \DateTime('2017-01-01');

        $dgClosed = new DirectGroupHandlerClaim();
        $dgClosed->policyNumber = $dgOpen->getPolicyNumber();
        $dgClosed->claimNumber = 'a';
        $dgClosed->status = 'Closed';
        $dgClosed->lossDate = new \DateTime('2017-02-01');

        self::$directGroupService->clearErrors();

        $this->assertEquals(0, count(self::$directGroupService->getErrors()));
        self::$directGroupService->saveClaims(1, [$dgOpen, $dgClosed]);
        $this->assertEquals(1, count(self::$directGroupService->getErrors()));

        $this->insureErrorExists('/\[R1\]/');
    }

    public function testSaveClaimsOpenClosedDb()
    {
        $policy1 = static::createUserPolicy(true);
        $policy1->getUser()->setEmail(static::generateEmail('testSaveClaimsOpenClosedDb-1', $this));
        $claim1 = new Claim();
        $claim1->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy1->addClaim($claim1);
        $claim1->setNumber(rand(1, 999999));
        $claim1->setType(Claim::TYPE_THEFT);

        static::$dm->persist($policy1->getUser());
        static::$dm->persist($policy1);
        static::$dm->persist($claim1);
        static::$dm->flush();

        $dgOpen = new DirectGroupHandlerClaim();
        $dgOpen->policyNumber = $policy1->getPolicyNumber();
        $dgOpen->claimNumber = $claim1->getNumber();
        $dgOpen->status = 'Open';
        $dgOpen->lossDate = new \DateTime('2017-01-01');

        $this->assertEquals(0, count(self::$directGroupService->getErrors()));
        self::$directGroupService->saveClaims(1, [$dgOpen]);

        $dgClosed = new DirectGroupHandlerClaim();
        $dgClosed->policyNumber = $dgOpen->getPolicyNumber();
        $dgClosed->claimNumber = 'a';
        $dgClosed->status = 'Closed';
        $dgClosed->lossDate = new \DateTime('2017-02-01');

        self::$directGroupService->clearErrors();

        $this->assertEquals(0, count(self::$directGroupService->getErrors()));
        self::$directGroupService->saveClaims(1, [$dgClosed]);

        // missing claim number
        //print_r(self::$directGroupService->getErrors());
        $this->assertEquals(1, count(self::$directGroupService->getErrors()));

        $this->insureErrorDoesNotExist('/\[R3\]/');
    }

    public function testSaveClaimsClosedOpen()
    {
        $dgOpen = new DirectGroupHandlerClaim();
        $dgOpen->policyNumber = self::getRandomPolicyNumber('TEST');
        $dgOpen->claimNumber = 'a';
        $dgOpen->status = 'Open';
        $dgOpen->lossDate = new \DateTime('2017-02-01');

        $dgClosed = new DirectGroupHandlerClaim();
        $dgClosed->policyNumber = $dgOpen->getPolicyNumber();
        $dgClosed->claimNumber = 'a';
        $dgClosed->status = 'Closed';
        $dgClosed->lossDate = new \DateTime('2017-01-01');

        self::$directGroupService->clearErrors();

        $this->assertEquals(0, count(self::$directGroupService->getErrors()));
        self::$directGroupService->saveClaims(1, [$dgOpen, $dgClosed]);
        $this->assertEquals(1, count(self::$directGroupService->getErrors()));
        $this->insureErrorDoesNotExist('/older then the closed claim/');
        $this->insureErrorExists('/Unable to locate claim/');
    }

    public function testSaveClaimsSaveException()
    {
        $policy1 = static::createUserPolicy(true);
        $policy1->getUser()->setEmail(static::generateEmail('testSaveClaimsSaveException-1', $this));
        $claim1 = new Claim();
        $claim1->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy1->addClaim($claim1);
        $claim1->setNumber('DG1');

        $policy2 = static::createUserPolicy(true);
        $policy2->getUser()->setEmail(static::generateEmail('testSaveClaimsSaveException-2', $this));
        $claim2 = new Claim();
        $claim2->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy2->addClaim($claim2);
        $claim2->setNumber('DG2');
        $claim2->setType(Claim::TYPE_THEFT);

        static::$dm->persist($policy1->getUser());
        static::$dm->persist($policy2->getUser());
        static::$dm->persist($policy1);
        static::$dm->persist($policy2);
        static::$dm->persist($claim1);
        static::$dm->persist($claim2);
        static::$dm->flush();

        $claim1Id = $claim1->getId();
        $claim2Id = $claim2->getId();

        // expected error
        $dgOpen1 = new DirectGroupHandlerClaim();
        $dgOpen1->claimNumber = 'DG1';
        $dgOpen1->policyNumber = $policy1->getPolicyNumber();
        $dgOpen1->status = 'Open';
        $dgOpen1->excess = 0;
        $dgOpen1->reserved = 1;
        $dgOpen1->riskPostCode = 'BX1 1LT';
        $dgOpen1->insuredName = 'Foo Bar';
        //$dgOpen1->lossType = DirectGroupHandlerClaim::TYPE_THEFT;

        // should be saved
        $dgOpen2 = new DirectGroupHandlerClaim();
        $dgOpen2->claimNumber = 'DG2';
        $dgOpen2->policyNumber = $policy2->getPolicyNumber();
        $dgOpen2->status = 'Open';
        $dgOpen2->excess = 0;
        $dgOpen2->reserved = 2;
        $dgOpen2->riskPostCode = 'BX1 1LT';
        $dgOpen2->insuredName = 'Foo Bar';
        $dgOpen2->lossType = DirectGroupHandlerClaim::TYPE_THEFT;

        self::$directGroupService->clearErrors();

        $this->assertEquals(0, count(self::$directGroupService->getErrors()));

        self::$directGroupService->saveClaims(2, [$dgOpen1, $dgOpen2]);

        // print_r(self::$directGroupService->getErrors());

        // Claims type does not match for claim 1 [Record import failed]
        $this->assertEquals(1, count(self::$directGroupService->getErrors()));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Claim::class);

        $updatedClaim1 = $repo->find($claim1Id);
        $updatedClaim2 = $repo->find($claim2Id);

        $this->assertNull($updatedClaim1->getReservedValue());
        $this->assertEquals(2, $updatedClaim2->getReservedValue());
    }

    public function testValidateClaimDetailsPolicyNumber()
    {
        $policy = new PhonePolicy();
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber('TEST/2016/123456');

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->policyNumber = 'TEST/2017/123456';

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $directGroupClaim,
            'does not match policy number '
        );
    }

    public function testValidateClaimDetailsInvalidStatus()
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostcode('BX11LT');
        $user = new User();
        $user->setBillingAddress($address);
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $policy->setPhone(self::getRandomPhone(self::$dm));
        $user->addPolicy($policy);
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber('TEST/2017/123456');

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->policyNumber = 'TEST/2017/123456';
        $directGroupClaim->replacementImei = $this->generateRandomImei();
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_WITHDRAWN;
        $directGroupClaim->insuredName = 'Mr Foo Bar';

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $directGroupClaim,
            'replacement IMEI Number, yet has a withdrawn/declined status'
        );
    }

    public function testDeclinedToApproved()
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostcode('BX11LT');
        $user = new User();
        $user->setEmail(static::generateEmail('testDeclinedToApproved', $this));
        $user->setBillingAddress($address);
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $policy->setPhone(self::getRandomPhone(self::$dm));
        $user->addPolicy($policy);
        $claim = new Claim();
        $claim->setNumber(self::getRandomClaimNumber());
        $claim->setStatus(Claim::STATUS_DECLINED);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        $policy->setPolicyNumber(self::getRandomPolicyNumber('TEST'));

        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->replacementImei = $this->generateRandomImei();
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        $directGroupClaim->insuredName = 'Mr Foo Bar';
        $directGroupClaim->lossDate = new \DateTime('2017-06-01');
        $directGroupClaim->replacementReceivedDate = new \DateTime('2017-07-01');

        self::$directGroupService->saveClaim($directGroupClaim, false);
        //print_r(self::$directGroupService->getErrors());
        //print_r(self::$directGroupService->getWarnings());
        //print_r(self::$directGroupService->getSoSureActions());

        $this->insureSoSureActionExists('/was previously closed/');
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Claim::class);
        $updatedClaim = $repo->find($claim->getId());
        $this->assertEquals(Claim::STATUS_SETTLED, $updatedClaim->getStatus());
    }

    public function testValidateClaimDetailsName()
    {
        $user = new User();
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $user->addPolicy($policy);
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber('TEST/2017/1234569');

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->policyNumber = 'TEST/2017/1234569';
        $directGroupClaim->insuredName = 'Mr Bar Foo';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $directGroupClaim,
            'does not match expected insuredName'
        );
    }

    public function testValidateClaimDetails()
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostcode('se152sz');
        $user = new User();
        $user->setBillingAddress($address);
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setPhone(self::getRandomPhone(self::$dm));

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);
        $policy->setPolicyNumber('TEST/2017/123456');

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->policyNumber = 'TEST/2017/123456';
        $directGroupClaim->reserved = 1;
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->riskPostCode = 'se152sz';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        //$directGroupClaim->type = DaviesClaim::TYPE_LOSS;

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->assertEquals(0, count(self::$directGroupService->getErrors()));
    }

    public function testValidateClaimDetailsSettledNoImei()
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostcode('se152sz');
        $user = new User();
        $user->setBillingAddress($address);
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setPhone(self::getRandomPhone(self::$dm));

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setNumber(self::getRandomClaimNumber());
        $policy->addClaim($claim);
        $policy->setPolicyNumber(self::getRandomPolicyNumber('TEST'));

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->reserved = 1;
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->riskPostCode = 'se152sz';
        $directGroupClaim->excess = 150;
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        //$directGroupClaim->type = DaviesClaim::TYPE_LOSS;

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->assertEquals(1, count(self::$directGroupService->getErrors()));
        $this->insureErrorExists('/settled without a replacement imei/');

        self::$directGroupService->clearErrors();

        $directGroupClaim->repairSupplier = 'foo';
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->assertEquals(0, count(self::$directGroupService->getErrors()));
        $this->insureErrorDoesNotExist('/settled without a replacement imei/');

        self::$directGroupService->clearErrors();

        $directGroupClaim->repairSupplier = null;
        $claim->setIgnoreWarningFlags(Claim::WARNING_FLAG_CLAIMS_IMEI_UNOBTAINABLE);
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->assertEquals(0, count(self::$directGroupService->getErrors()));
        $this->insureErrorDoesNotExist('/settled without a replacement imei/');
    }

    public function testValidateClaimDetailsInvalidPolicyNumber()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->policyNumber = -1;
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $directGroupClaim,
            'does not match policy number'
        );
    }

    public function testValidateClaimDetailsInvalidName()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr Patrick McAndrew';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $directGroupClaim,
            'does not match expected insuredName'
        );
    }

    public function testValidateClaimDetailsInvalidReceivedDate()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->lossDate = new \DateTime('2017-07-01');
        $directGroupClaim->replacementReceivedDate = new \DateTime('2017-06-01');
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $directGroupClaim,
            'replacement received date prior to loss date'
        );
    }

    public function testValidateClaimDetailsInvalidPostcode()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->riskPostCode = 'se152sz';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureWarningExists('/does not match expected postcode/');
    }

    public function testValidateClaimDetailsInvalidPostcodeClosedRecent()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->riskPostCode = 'se152sz';
        $yesterday = \DateTime::createFromFormat('U', time());
        $yesterday = $yesterday->sub(new \DateInterval('P1D'));
        $directGroupClaim->dateClosed = $yesterday;

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureWarningExists('/does not match expected postcode/');
    }

    public function testValidateClaimDetailsInvalidPostcodeClosedOld()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->riskPostCode = 'se152sz';
        $fiveDaysAgo = \DateTime::createFromFormat('U', time());
        $fiveDaysAgo = $fiveDaysAgo->sub(new \DateInterval('P5D'));
        $directGroupClaim->dateClosed = $fiveDaysAgo;

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorDoesNotExist('/does not match expected postcode/');
    }

    public function testValidateClaimDetailsMissingReserved()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorExists('/does not have a reserved value/');
    }

    public function testValidateClaimDetailsIncorrectExcess()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->lossType = "Loss - From Pocket";
        $directGroupClaim->excess = 50;
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorExists('/does not have the correct excess value/');

        self::$directGroupService->clearErrors();

        $claim->setIgnoreWarningFlags(Claim::WARNING_FLAG_CLAIMS_INCORRECT_EXCESS);
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorDoesNotExist('/does not have the correct excess value/');
    }

    public function testValidateClaimDetailsCorrectExcessPicsure()
    {
        $policy = static::createUserPolicy(true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->lossType = "Loss - From Pocket";
        $directGroupClaim->excess = 70;
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorDoesNotExist('/does not have the correct excess value/');
    }

    public function testValidateClaimDetailsIncorrectExcessPicsureHigh()
    {
        $policy = static::createUserPolicy(true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->lossType = "Loss - From Pocket";
        $directGroupClaim->excess = 150;
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorExists('/does not have the correct excess value/');
    }

    public function testValidateClaimDetailsIncorrectExcessPicsureLow()
    {
        $policy = static::createUserPolicy(true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->lossType = "Loss - From Pocket";
        $directGroupClaim->excess = 70;
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 500;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorExists('/does not have the correct excess value/');
    }

    public function testValidateClaimDetailsClosedWithReserve()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        $directGroupClaim->lossType = "Loss - From Pocket";
        $directGroupClaim->reserved = 10;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorExists('/still has a reserve fee/');
    }

    public function testValidateClaimDetailsReservedPresent()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 1;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorDoesNotExist('/does not have a reserved value/');
    }

    public function testValidateClaimDetailsIncurredPresent()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 1;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorDoesNotExist('/does not have a reserved value/');
    }

    public function testValidateClaimPhoneReplacementCostsCorrect()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $twelveDaysAgo = \DateTime::createFromFormat('U', time());
        $twelveDaysAgo = $twelveDaysAgo->sub(new \DateInterval('P12D'));
        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 6.68;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;
        $directGroupClaim->phoneReplacementCost = -50;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 150;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone';
        $directGroupClaim->replacementReceivedDate = $twelveDaysAgo;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorDoesNotExist('/does not have the correct phone replacement cost/');

        $directGroupClaim->status = 'Paid Closed';
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorExists('/does not have the correct phone replacement cost/');
    }

    public function testValidateClaimPhoneReplacementCostsCorrectForMasterCard()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_DAMAGE);
        $policy->addClaim($claim);

        $twelveDaysAgo = \DateTime::createFromFormat('U', time());
        $twelveDaysAgo = $twelveDaysAgo->sub(new \DateInterval('P12D'));
        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 6.68;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 150;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone';
        $directGroupClaim->replacementReceivedDate = $twelveDaysAgo;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->replacementSupplier = DirectGroupHandlerClaim::SUPPLIER_MASTERCARD;

        $directGroupClaim->status = 'Paid Closed';
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorDoesNotExist('/does not have the correct phone replacement cost/');
    }

    public function testValidateClaimPhoneReplacementCostsCorrectTooRecent()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $fourDaysAgo = \DateTime::createFromFormat('U', time());
        $fourDaysAgo = $fourDaysAgo->sub(new \DateInterval('P4D'));
        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 6.68;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;
        $directGroupClaim->phoneReplacementCost = -50;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 6;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone';
        $directGroupClaim->replacementReceivedDate = $fourDaysAgo;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorDoesNotExist('/does not have the correct phone replacement cost/');
    }

    public function testValidateClaimDetailsIncurredCorrect()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 3.11;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;
        $directGroupClaim->phoneReplacementCost = 151.07;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 150;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorDoesNotExist('/does not have the correct incurred value/');
    }

    public function testValidateClaimDetailsIncurredIncorrect()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 1;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;
        $directGroupClaim->phoneReplacementCost = 1.07;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 150;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorExists('/does not have the correct incurred value/');
    }

    public function testValidateClaimDetailsIncurredIncorrectForFees()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 1;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;
        $directGroupClaim->phoneReplacementCost = 1.07;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 150;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorExists('/does not have the correct incurred value/');
    }

    /**
     *  cost warning is off should not trigger
     */
    public function testValidateClaimDetailsReplacementCostNoWarning()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setApprovedDate(new \DateTime('2016-01-02'));
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 1;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;
        // replacement cost is bigger than initial price, should create warning
        $replacementCost = $policy->getPhone()->getInitialPrice() + 10;
        $directGroupClaim->phoneReplacementCost = $replacementCost;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 6;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 8';
        $directGroupClaim->replacementReceivedDate = new \DateTime('2016-01-01');
        // set ignore warning flag
        $claim->setIgnoreWarningFlags(Claim::WARNING_FLAG_CLAIMS_REPLACEMENT_COST_HIGHER);
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureWarningDoesNotExist('/Device replacement cost/');
    }

    /**
     * cost warning is on and should trigger
     */
    public function testValidateClaimDetailsReplacementCostWarning()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setApprovedDate(new \DateTime('2016-01-02'));
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 1;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;

        // replacement cost is bigger than initial price
        $replacementCost = $policy->getPhone()->getInitialPrice() + 10;
        $directGroupClaim->phoneReplacementCost = $replacementCost;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 6;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 8';
        $directGroupClaim->replacementReceivedDate = new \DateTime('2016-01-01');
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);

        // all ignore warning flags are off
        $claim->clearIgnoreWarningFlags();

        // there should be a warning
        $this->insureWarningExists('/Device replacement cost/');
    }

    /**
     * warning flag on but price is the same
     */
    public function testValidateClaimDetailsReplacementCostWarningOn()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setApprovedDate(new \DateTime('2016-01-02'));
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 1;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;

        // price is the same as initial price
        $replacementCost = $policy->getPhone()->getInitialPrice();
        $directGroupClaim->phoneReplacementCost = $replacementCost;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 6;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 8';
        $directGroupClaim->replacementReceivedDate = new \DateTime('2016-01-01');
        // all ignore warning flags are off
        $claim->clearIgnoreWarningFlags();
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        // we are not expecting warning
        $this->insureWarningDoesNotExist('/Device replacement cost/');
    }

    public function testValidateClaimDetailsReceivedDate()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setApprovedDate(new \DateTime('2016-01-04'));
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 1;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;
        $directGroupClaim->phoneReplacementCost = 1.07;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 6;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 8';
        $directGroupClaim->replacementReceivedDate = new \DateTime('2016-01-01');

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureWarningExists('/delayed replacement date/');
    }

    public function testValidateClaimDetailsReceivedDateTooOld()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        // 3 months!
        $claim->setApprovedDate(new \DateTime('2016-04-01'));
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 1;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;
        $directGroupClaim->phoneReplacementCost = 1.07;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 6;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 8';
        $directGroupClaim->replacementReceivedDate = new \DateTime('2016-01-01');

        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorExists('/should be closed. Replacement was delivered more than/');

        self::$directGroupService->clearErrors();

        $directGroupClaim->replacementReceivedDate = new \DateTime('-20 days');
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorDoesNotExist('/should be closed. Replacement was delivered more than 1 month ago/');
    }

    public function testValidateClaimDetailsMissingReceivedInfo()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        // 2 weeks
        $twoWeekAgo = \DateTime::createFromFormat('U', time());
        $twoWeekAgo = $twoWeekAgo->sub(new \DateInterval('P14D'));
        $claim->setApprovedDate($twoWeekAgo);
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;
        $directGroupClaim->status = 'open';
        $directGroupClaim->totalIncurred = 1;
        $directGroupClaim->unauthorizedCalls = 1.01;
        $directGroupClaim->accessories = 1.03;
        $directGroupClaim->phoneReplacementCost = 0;
        $directGroupClaim->handlingFees = 1.19;
        $directGroupClaim->excess = 6;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        //$directGroupClaim->replacementMake = 'Apple';
        //$directGroupClaim->replacementModel = 'iPhone 8';
        //$directGroupClaim->replacementReceivedDate = new \DateTime('2016-01-01');

        $directGroupClaim->status = 'open';
        $directGroupClaim->miStatus = null;
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureSoSureActionExists('/previously approved, however no longer appears to be/');
        $this->insureErrorDoesNotExist('/the replacement data not recorded/');
        $this->insureErrorDoesNotExist('/received date/');
        $this->insureErrorDoesNotExist('/imei/');
        $this->insureErrorDoesNotExist('/; phone/');

        self::$directGroupService->clearErrors();
        self::$directGroupService->clearSoSureActions();

        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 8';
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureSoSureActionExists('/previously approved, however no longer appears to be/');
        $this->insureErrorDoesNotExist('/the replacement data not recorded/');
        $this->insureErrorDoesNotExist('/received date/');
        $this->insureErrorDoesNotExist('/imei/');
        $this->insureErrorDoesNotExist('/; phone/');

        self::$directGroupService->clearErrors();
        self::$directGroupService->clearSoSureActions();

        $directGroupClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorExists('/the replacement data not recorded/');
        $this->insureErrorDoesNotExist('/received date/');
        $this->insureErrorExists('/imei/');
        $this->insureErrorDoesNotExist('/; phone/');

        self::$directGroupService->clearErrors();
        self::$directGroupService->clearSoSureActions();

        $directGroupClaim->replacementImei = $this->generateRandomImei();
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureErrorDoesNotExist('/the replacement data not recorded/');
        $this->insureErrorDoesNotExist('/received date/');
        $this->insureErrorDoesNotExist('/imei/');
        $this->insureErrorDoesNotExist('/; phone/');
    }

    public function testPostValidateClaimDetailsReceivedDate()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setApprovedDate(new \DateTime('2016-01-02'));
        $claim->setReplacementReceivedDate(new \DateTime('2016-01-01'));
        $policy->addClaim($claim);

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = 1;

        self::$directGroupService->postValidateClaimDetails($claim, $directGroupClaim);
        $this->insureWarningExists('/has an approved date/');
    }

    public function testSaveClaimsReplacementDate()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsReplacementDate', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = (string) $claim->getNumber();
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $directGroupClaim->replacementReceivedDate = new \DateTime('2016-01-02');
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 4';

        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $mailer->expects($this->never())->method('send');
        self::$directGroupService->setMailerMailer($mailer);

        static::$directGroupService->saveClaim($directGroupClaim, false);
        $this->assertNotNull($claim->getApprovedDate());
        $this->assertEquals(new \DateTime('2016-01-01'), $claim->getApprovedDate());
    }

    public function testSaveClaimsRepairImei()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsRepairImei', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = (string) $claim->getNumber();
        $directGroupClaim->excess = 150;
        $directGroupClaim->phoneReplacementCost = 300;
        $directGroupClaim->totalIncurred = 150;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED_REPAIRED;
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $directGroupClaim->replacementReceivedDate = new \DateTime('2016-01-02');
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 4';
        $directGroupClaim->replacementImei = static::generateRandomImei();

        static::$directGroupService->saveClaim($directGroupClaim, false);
        //print_r(static::$directGroupService->getErrors());
        $this->assertEquals(
            1,
            count(self::$directGroupService->getErrors()[$claim->getNumber()]),
            json_encode(self::$directGroupService->getErrors())
        );
        $this->insureErrorExists('/repaired claim, but replacement imei is present/');
    }

    public function testSaveClaimsRepairNoSupplier()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsRepairNoSupplier', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = (string) $claim->getNumber();
        $directGroupClaim->excess = 200;
        $directGroupClaim->phoneReplacementCost = 50;
        $directGroupClaim->totalIncurred = 150;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED_REPAIRED;
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $directGroupClaim->replacementReceivedDate = new \DateTime('2016-01-02');
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 4';
        $directGroupClaim->replacementImei = static::generateRandomImei();
        $directGroupClaim->lossDescription = 'I lost my phone.';
        $directGroupClaim->initialSuspicion = true;

        static::$directGroupService->saveClaim($directGroupClaim, false);
        //print_r(static::$directGroupService->getWarnings());
        $this->assertEquals(
            2,
            count(self::$directGroupService->getWarnings()[$claim->getNumber()]),
            json_encode(self::$directGroupService->getWarnings())
        );
        $this->insureWarningExists('/repaired claim, but no supplier set/');
    }

    public function testSaveClaimValidationError()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimValidationError', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = (string) $claim->getNumber();
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $directGroupClaim->replacementReceivedDate = new \DateTime('2016-01-02');
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 4';
        static::$directGroupService->saveClaim($directGroupClaim, false);
        $this->assertEquals(new \DateTime('2016-01-01'), $claim->getApprovedDate());
        $this->assertEquals(0, $claim->getIncurred());

        // fail validation
        $directGroupClaim->location = '☺';
        $directGroupClaim->totalIncurred = 1;
        static::$directGroupService->saveClaim($directGroupClaim, false);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Claim::class);
        $updatedClaim = $repo->find($claim->getId());
        $this->assertEquals(0, $updatedClaim->getIncurred());

        $directGroupClaim->location = null;
        $directGroupClaim->totalIncurred = 2;
        static::$directGroupService->saveClaim($directGroupClaim, false);

        $updatedClaim = $repo->find($claim->getId());
        $this->assertEquals(2, $updatedClaim->getIncurred());
    }

    public function testSaveClaimsNegativePhoneValue()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsNegativePhoneValue', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->phoneReplacementCost = -70;
        $directGroupClaim->totalIncurred = -70;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;

        $now = \DateTime::createFromFormat('U', time());
        $yesterday = $this->subBusinessDays($now, 1);
        static::$directGroupService->saveClaim($directGroupClaim, false);

        $this->assertEquals(Claim::STATUS_APPROVED, $claim->getStatus());
        $this->assertEquals($yesterday, $claim->getApprovedDate());
    }

    public function testSaveClaimsYesterday()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsYesterday', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;

        $now = \DateTime::createFromFormat('U', time());
        $yesterday = $this->subBusinessDays($now, 1);
        static::$directGroupService->saveClaim($directGroupClaim, false);
        $this->assertEquals($yesterday, $claim->getApprovedDate());
    }

    private function insureWarningExists($warningRegEx)
    {
        $this->insureWarningExistsOrNot($warningRegEx, true);
    }

    private function insureWarningDoesNotExist($warningRegEx)
    {
        $this->insureWarningExistsOrNot($warningRegEx, false);
    }

    private function insureWarningExistsOrNot($warningRegEx, $exists)
    {
        $foundMatch = false;
        foreach (self::$directGroupService->getWarnings() as $warning) {
            $matches = preg_grep($warningRegEx, $warning);
            if (count($matches) > 0) {
                $foundMatch = true;
            }
        }
        if ($exists) {
            $this->assertTrue($foundMatch);
        } else {
            $this->assertFalse($foundMatch);
        }
    }

    private function insureErrorExists($errorRegEx)
    {
        $this->insureErrorExistsOrNot($errorRegEx, true);
    }

    private function insureErrorDoesNotExist($errorRegEx)
    {
        $this->insureErrorExistsOrNot($errorRegEx, false);
    }

    private function insureErrorExistsOrNot($errorRegEx, $exists)
    {
        $foundMatch = false;
        foreach (self::$directGroupService->getErrors() as $error) {
            $matches = preg_grep($errorRegEx, $error);
            if (count($matches) > 0) {
                $foundMatch = true;
            }
        }
        if ($exists) {
            $this->assertTrue(
                $foundMatch,
                sprintf('did not find %s in %s', $errorRegEx, json_encode(self::$directGroupService->getErrors()))
            );
        } else {
            $this->assertFalse(
                $foundMatch,
                sprintf('found %s in %s', $errorRegEx, json_encode(self::$directGroupService->getErrors()))
            );
        }
    }

    private function insureSoSureActionExists($errorRegEx)
    {
        $this->insureSoSureActionExistsOrNot($errorRegEx, true);
    }

    private function insureSoSureActionDoesNotExist($errorRegEx)
    {
        $this->insureSoSureActionExistsOrNot($errorRegEx, false);
    }

    private function insureSoSureActionExistsOrNot($errorRegEx, $exists)
    {
        $foundMatch = false;
        foreach (self::$directGroupService->getSoSureActions() as $error) {
            $matches = preg_grep($errorRegEx, $error);
            if (count($matches) > 0) {
                $foundMatch = true;
            }
        }
        if ($exists) {
            $this->assertTrue($foundMatch, sprintf(
                'did not find %s in %s',
                $errorRegEx,
                json_encode(self::$directGroupService->getSoSureActions())
            ));
        } else {
            $this->assertFalse($foundMatch, sprintf(
                'found %s in %s',
                $errorRegEx,
                json_encode(self::$directGroupService->getSoSureActions())
            ));
        }
    }

    private function insureFeesExists($feesRegEx)
    {
        $this->insureFeesExistsOrNot($feesRegEx, true);
    }

    private function insureFeesDoesNotExist($feesRegEx)
    {
        $this->insureFeesExistsOrNot($feesRegEx, false);
    }

    private function insureFeesExistsOrNot($feesRegEx, $exists)
    {
        $foundMatch = false;
        foreach (self::$directGroupService->getFees() as $fee) {
            $matches = preg_grep($feesRegEx, $fee);
            if (count($matches) > 0) {
                $foundMatch = true;
            }
        }
        if ($exists) {
            $this->assertTrue($foundMatch);
        } else {
            $this->assertFalse($foundMatch);
        }
    }

    private function validateClaimsDetailsThrowsException($claim, $directGroupClaim, $message)
    {
        $exception = false;
        try {
            self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        } catch (\Exception $e) {
            $exception = true;
            $this->assertContains($message, $e->getMessage());
        }
        $this->assertTrue($exception);
    }

    private function generateUserPolicyClaim($email, $policyNumber)
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostcode('BX11LT');
        $user = new User();
        $user->setBillingAddress($address);
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $user->setEmail($email);
        $policy = new PhonePolicy();
        $user->addPolicy($policy);
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber($policyNumber);

        return $claim;
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Invalid replacement imei invalid
     */
    public function testSaveClaimsInvalidReplacementImei()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsInvalidReplacementImei', $this));
        $claim = new Claim();
        $claim->setNumber($this->getRandomClaimNumber());
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 10;
        $directGroupClaim->initialSuspicion = false;
        $directGroupClaim->finalSuspicion = false;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $directGroupClaim->lossDescription = 'min length';
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 4';
        $directGroupClaim->replacementImei = 'invalid';
        $directGroupClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $this->assertFalse(static::$directGroupService->saveClaim($directGroupClaim, false));
        $this->assertEquals(
            0,
            count(self::$directGroupService->getErrors()),
            json_encode(self::$directGroupService->getErrors())
        );
        $this->assertEquals(
            0,
            count(self::$directGroupService->getWarnings()),
            json_encode(self::$directGroupService->getWarnings())
        );
    }

    public function testSaveClaimValidReplacementImei()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimValidReplacementImei', $this));
        $claim = new Claim();
        $claim->setNumber($this->getRandomClaimNumber());
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 10;
        $directGroupClaim->initialSuspicion = false;
        $directGroupClaim->finalSuspicion = false;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $directGroupClaim->lossDescription = 'min length';
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 4';
        $directGroupClaim->replacementImei = $this->generateRandomImei();
        $directGroupClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $this->assertTrue(static::$directGroupService->saveClaim($directGroupClaim, false));
        $this->assertEquals(
            0,
            count(self::$directGroupService->getErrors()),
            json_encode(self::$directGroupService->getErrors())
        );
        $this->assertEquals(
            0,
            count(self::$directGroupService->getWarnings()),
            json_encode(self::$directGroupService->getWarnings())
        );
    }

    public function testSaveClaimUnknownReplacementImei()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimUnknownReplacementImei', $this));
        $claim = new Claim();
        $claim->setNumber($this->getRandomClaimNumber());
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->totalIncurred = 0;
        $directGroupClaim->reserved = 10;
        $directGroupClaim->initialSuspicion = false;
        $directGroupClaim->finalSuspicion = false;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_OPEN;
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 4';
        // fromArray will transform replacementImei = 'Unable to obtain' to a null value and add to unobtainableFields
        // $directGroupClaim->replacementImei = 'Unable to obtain';
        $directGroupClaim->unobtainableFields[] = 'replacementImei';

        $directGroupClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $this->assertTrue(static::$directGroupService->saveClaim($directGroupClaim, false));
        $this->assertEquals(
            0,
            count(self::$directGroupService->getErrors()),
            json_encode(self::$directGroupService->getErrors())
        );
        $this->assertEquals(
            1,
            count(self::$directGroupService->getWarnings()),
            json_encode(self::$directGroupService->getWarnings())
        );
        $this->insureWarningExists('/does not have a replacement IMEI/');

        self::$directGroupService->clearWarnings();
        self::$directGroupService->postValidateClaimDetails($claim, $directGroupClaim);
        $this->insureWarningExists('/noted as unobtainable/');

        $claim->setIgnoreWarningFlags(Claim::WARNING_FLAG_CLAIMS_IMEI_UNOBTAINABLE);

        self::$directGroupService->clearWarnings();
        self::$directGroupService->validateClaimDetails($claim, $directGroupClaim);
        $this->insureWarningDoesNotExist('/does not have a replacement IMEI/');

        self::$directGroupService->postValidateClaimDetails($claim, $directGroupClaim);
        $this->insureWarningDoesNotExist('/noted as unobtainable/');
    }

    public function testReportMissingClaims()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testReportMissingClaims', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);

        $yesterday = \DateTime::createFromFormat('U', time());
        $yesterday = $yesterday->sub(new \DateInterval('P1D'));
        $claim->setRecordedDate($yesterday);

        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        //$directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        $directGroupClaim->finalSuspicion = null;
        $directGroupClaim->initialSuspicion = null;
        $directGroupClaim->finalSuspicion = null;
        $directGroupClaim->lossDescription = '1234';
        $directGroupClaim->claimNumber = rand(1, 999999);

        $directGroupClaims = array($directGroupClaim);

        self::$directGroupService->reportMissingClaims($directGroupClaims);
        // print_r(self::$directGroupService->getErrors());

        $this->insureErrorExists('/'. $claim->getNumber() .'/');
        $this->insureErrorExists('/'. preg_quote($claim->getPolicy()->getPolicyNumber(), '/') .'/');
    }

    public function testSaveClaimMissingReplacementImeiPhone()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('DGtestSaveClaimMissingReplacementImeiPhone', $this));
        $claim = new Claim();
        $claim->setNumber($this->getRandomClaimNumber());
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        $this->assertNotNull($policy->getPhone());
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->totalIncurred = 150;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->excess = 150;
        $directGroupClaim->initialSuspicion = false;
        $directGroupClaim->finalSuspicion = false;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        $directGroupClaim->lossDescription = 'min length';
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 4';
        //$directGroupClaim->replacementImei = $this->generateRandomImei();
        $directGroupClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $this->assertTrue(static::$directGroupService->saveClaim($directGroupClaim, false));
        $this->assertEquals(
            2,
            count(self::$directGroupService->getErrors()[$claim->getNumber()]),
            json_encode(self::$directGroupService->getErrors())
        );
        $this->assertEquals(
            0,
            count(self::$directGroupService->getWarnings()),
            json_encode(self::$directGroupService->getWarnings())
        );
        $this->insureErrorExists('/settled without a replacement imei/');
        $this->insureSoSureActionExists('/settled without a replacement phone/');

        self::$directGroupService->clearErrors();
        self::$directGroupService->clearWarnings();
        self::$directGroupService->clearFees();
        self::$directGroupService->clearSoSureActions();

        $directGroupClaim->replacementImei = $this->generateRandomImei();
        $this->assertTrue(static::$directGroupService->saveClaim($directGroupClaim, false));
/*
        $this->assertTrue(isset(self::$directGroupService->getErrors()[$claim->getNumber()]));
        $this->assertEquals(
            1,
            count(self::$directGroupService->getErrors()[$claim->getNumber()]),
            json_encode(self::$directGroupService->getErrors())
        );*/
        $this->insureErrorDoesNotExist('/settled without a replacement imei/');
    }

    public function testSaveClaimMissingReplacementPhone()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimMissingReplacementPhone', $this));
        $claim = new Claim();
        $claim->setNumber($this->getRandomClaimNumber());
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setReplacementPhone(static::$phone);
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->claimNumber = $claim->getNumber();
        $directGroupClaim->totalIncurred = 150;
        $directGroupClaim->reserved = 0;
        $directGroupClaim->excess = 150;
        $directGroupClaim->initialSuspicion = false;
        $directGroupClaim->finalSuspicion = false;
        $directGroupClaim->policyNumber = $policy->getPolicyNumber();
        $directGroupClaim->insuredName = 'Mr foo bar';
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        $directGroupClaim->lossDescription = 'min length';
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 4';
        //$directGroupClaim->replacementImei = $this->generateRandomImei();
        $directGroupClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $this->assertTrue(static::$directGroupService->saveClaim($directGroupClaim, false));
        $this->assertEquals(
            2,
            count(self::$directGroupService->getErrors()[$claim->getNumber()]),
            json_encode(self::$directGroupService->getErrors())
        );
        $this->assertEquals(
            0,
            count(self::$directGroupService->getWarnings()),
            json_encode(self::$directGroupService->getWarnings())
        );
        $this->insureErrorDoesNotExist('/settled without a replacement phone/');
    }

    public function testSaveClaimsNoClaimsFound()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsNoClaimsFound', $this));
        $claim1 = new Claim();
        $claim1->setType(Claim::TYPE_LOSS);
        $claim1->setStatus(Claim::STATUS_APPROVED);
        $claim1->setNumber($this->getRandomClaimNumber());
        $claim1->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim1);

        $yesterday = \DateTime::createFromFormat('U', time());
        $yesterday = $yesterday->sub(new \DateInterval('P1D'));
        $claim2 = new Claim();
        $claim2->setRecordedDate($yesterday);
        $claim2->setType(Claim::TYPE_LOSS);
        $claim2->setStatus(Claim::STATUS_APPROVED);
        $claim2->setNumber($this->getRandomClaimNumber());
        $claim2->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim2);

        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim1);
        static::$dm->persist($claim2);
        static::$dm->flush();

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->policyNumber = $claim1->getPolicy()->getPolicyNumber();
        $directGroupClaim->claimNumber = $claim2->getNumber();
        $directGroupClaim->insuredName = 'foo bar';
        $directGroupClaim->initialSuspicion = false;
        $directGroupClaim->finalSuspicion = false;
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 4';
        $directGroupClaim->replacementImei = '123 Bx11lt';
        $directGroupClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $directGroupClaim->phoneReplacementCost = 250;
        $directGroupClaim->excess = 150;
        $directGroupClaim->totalIncurred = 100;
        $directGroupClaims = array($directGroupClaim);

        static::$directGroupService->saveClaims('', $directGroupClaims);
        $this->insureErrorDoesNotExist('/'.$claim1->getNumber().'/');
        $this->insureErrorDoesNotExist('/'.$claim2->getNumber().'/');

        $directGroupClaim = new DirectGroupHandlerClaim();
        $directGroupClaim->policyNumber = $claim1->getPolicy()->getPolicyNumber();
        $directGroupClaim->claimNumber = $this->getRandomClaimNumber();
        $directGroupClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        $directGroupClaim->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $directGroupClaim->insuredName = 'foo bar';
        $directGroupClaim->initialSuspicion = 'no';
        $directGroupClaim->finalSuspicion = 'no';
        $directGroupClaim->replacementMake = 'Apple';
        $directGroupClaim->replacementModel = 'iPhone 4';
        $directGroupClaim->replacementImei = '123 Bx11lt';
        $directGroupClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $directGroupClaims = array($directGroupClaim);

        static::$directGroupService->saveClaims('', $directGroupClaims);
        $this->insureErrorDoesNotExist('/'.$claim1->getNumber().'/');
        $this->insureErrorExists('/'.$claim2->getNumber().'/');
    }
}
