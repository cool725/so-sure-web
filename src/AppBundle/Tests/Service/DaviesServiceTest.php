<?php

namespace AppBundle\Tests\Service;

use AppBundle\Classes\DirectGroupHandlerClaim;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Service\DaviesService;
use AppBundle\Tests\Form\Type\ClaimTypeTest;
use Doctrine\ODM\MongoDB\DocumentManager;
use SebastianBergmann\ObjectReflector\TestFixture\ClassWithIntegerAttributeName;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use AppBundle\Document\User;
use AppBundle\Document\Charge;
use AppBundle\Document\DateTrait;

use AppBundle\Classes\DaviesHandlerClaim;

/**
 * @group functional-nonet
 * AppBundle\\Tests\\Service\\DaviesServiceTest
 */
class DaviesServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use DateTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var DaviesService */
    protected static $daviesService;
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
        /** @var DaviesService */
         $daviesService = self::$container->get('app.davies');
         self::$daviesService = $daviesService;

        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 6', 'memory' => 64]);
        self::$phoneA = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phoneB = $phoneRepo->findOneBy(['devices' => 'A0001', 'memory' => 64]);

        static::$policyTerms = new PolicyTerms();
        static::$policyTerms->setVersion(PolicyTerms::VERSION_10);

        static::$nonPicSurePolicyTerms = new PolicyTerms();
        static::$nonPicSurePolicyTerms->setVersion(PolicyTerms::VERSION_1);
    }

    public function setUp()
    {
        self::$daviesService->clearErrors();
        self::$daviesService->clearWarnings();
        self::$daviesService->clearFees();
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 6', 'memory' => 64]);
        self::$phoneA = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phoneB = $phoneRepo->findOneBy(['devices' => 'A0001', 'memory' => 64]);
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

        $davies = new DaviesHandlerClaim();

        self::$daviesService->updatePolicy($claim, $davies, false);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        $claimB = new Claim();
        $claimB->setReplacementPhone(self::$phoneB);
        $claimB->setReplacementImei($imeiNew);
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claimB);

        $daviesB = new DaviesHandlerClaim();
        $daviesB->replacementMake = 'Apple';
        $daviesB->replacementModel = 'iPhone 4';

        self::$daviesService->updatePolicy($claimB, $daviesB, false);
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

        $davies = new DaviesHandlerClaim();

        self::$daviesService->updatePolicy($claim, $davies, false);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        $claim->setStatus(Claim::STATUS_SETTLED);

        $claimB = new Claim();
        $claimB->setReplacementPhone(self::$phoneB);
        $claimB->setReplacementImei($imeiNew);
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claimB);

        $daviesB = new DaviesHandlerClaim();
        $daviesB->replacementMake = 'Apple';
        $daviesB->replacementModel = 'iPhone 4';

        self::$daviesService->updatePolicy($claimB, $daviesB, false);
        $this->assertEquals($imeiNew, $policy->getImei());
        $this->assertEquals(self::$phoneB->getId(), $policy->getPhone()->getId());

        // Rerunning old settled claim should keep the newer imei
        $this->assertEquals(Claim::STATUS_SETTLED, $claim->getStatus());
        self::$daviesService->updatePolicy($claim, $davies, false);
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

        $davies = new DaviesHandlerClaim();

        self::$daviesService->updatePolicy($claim, $davies, false);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        $claimB = new Claim();
        $claimB->setReplacementPhone(self::$phoneB);
        $claimB->setReplacementImei($imeiNew);
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claimB);

        $daviesB = new DaviesHandlerClaim();
        $daviesB->replacementMake = 'Apple';
        $daviesB->replacementModel = 'iPhone 4';

        self::$daviesService->updatePolicy($claimB, $daviesB, true);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        // Rerunning old settled claim should keep the newer imei
        $this->assertEquals(Claim::STATUS_APPROVED, $claim->getStatus());
        self::$daviesService->updatePolicy($claim, $davies, true);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());
    }

    public function testGetPolicyNumber()
    {
        $davies = new DaviesHandlerClaim();
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
        $daviesOpen = new DaviesHandlerClaim();
        $daviesOpen->policyNumber = $policyOpen->getPolicyNumber();
        $daviesOpen->claimNumber = $claimOpen->getNumber();
        $daviesOpen->status = 'Open';
        $daviesOpen->lossDate = new \DateTime('2017-02-01');

        $policyClosed = static::createUserPolicy(true);
        $policyClosed->getUser()->setEmail(static::generateEmail('testSaveClaimsClosed-Closed', $this));
        $claimClosed = new Claim();
        $claimClosed->setNumber(self::getRandomPolicyNumber('TEST'));
        $policyClosed->addClaim($claimClosed);
        $daviesClosed = new DaviesHandlerClaim();
        $daviesClosed->policyNumber = $policyClosed->getPolicyNumber();
        $daviesClosed->claimNumber = $claimClosed->getNumber();
        $daviesClosed->status = 'Closed';
        $daviesClosed->lossDate = new \DateTime('2017-01-01');

        self::$daviesService->saveClaims(1, [$daviesClosed, $daviesClosed]);
        self::$daviesService->saveClaims(1, [$daviesClosed, $daviesOpen]);

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testSaveClaimsDirectGroup()
    {
        $policyOpen = static::createUserPolicy(true);
        $policyOpen->getUser()->setEmail(static::generateEmail('testSaveClaimsDirectGroup', $this));
        $claimOpen = new Claim();
        $claimOpen->setNumber(self::getRandomPolicyNumber('TEST'));
        $claimOpen->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policyOpen->addClaim($claimOpen);
        static::$dm->persist($policyOpen->getUser());
        static::$dm->persist($policyOpen);
        static::$dm->persist($claimOpen);
        static::$dm->flush();

        $daviesOpen = new DaviesHandlerClaim();
        $daviesOpen->policyNumber = $policyOpen->getPolicyNumber();
        $daviesOpen->claimNumber = $claimOpen->getNumber();
        $daviesOpen->status = 'Open';
        $daviesOpen->lossDate = new \DateTime('2017-02-01');
        static::$dm->flush();

        $this->assertFalse(self::$daviesService->saveClaim($daviesOpen, false));
        $this->insureSoSureActionExists('/Skipping davies import/');
    }

    public function testSaveClaimsNoHandlingTeam()
    {
        $policyOpen = static::createUserPolicy(true);
        $policyOpen->getUser()->setEmail(static::generateEmail('testSaveClaimsNoHandlingTeam', $this));
        $claimOpen = new Claim();
        $claimOpen->setNumber(self::getRandomPolicyNumber('TEST'));
        $policyOpen->addClaim($claimOpen);
        $daviesOpen = new DaviesHandlerClaim();
        $daviesOpen->policyNumber = $policyOpen->getPolicyNumber();
        $daviesOpen->claimNumber = $claimOpen->getNumber();
        $daviesOpen->status = 'Open';
        $daviesOpen->lossDate = new \DateTime('2017-02-01');
        static::$dm->persist($policyOpen->getUser());
        static::$dm->persist($policyOpen);
        static::$dm->persist($claimOpen);
        static::$dm->flush();

        $this->assertFalse(self::$daviesService->saveClaim($daviesOpen, false));
        $this->insureSoSureActionExists('/Skipping davies import/');
    }

    public function testSaveClaimsOpen()
    {
        $policyOpen = static::createUserPolicy(true);
        $policyOpen->getUser()->setEmail(static::generateEmail('testSaveClaimsOpen-Open', $this));
        $claimOpen = new Claim();
        $claimOpen->setNumber(self::getRandomPolicyNumber('TEST'));
        $policyOpen->addClaim($claimOpen);
        $daviesOpen = new DaviesHandlerClaim();
        $daviesOpen->policyNumber = $policyOpen->getPolicyNumber();
        $daviesOpen->claimNumber = $claimOpen->getNumber();
        $daviesOpen->status = 'Open';
        $daviesOpen->lossDate = new \DateTime('2017-02-01');

        self::$daviesService->clearErrors();

        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->saveClaims(1, [$daviesOpen, $daviesOpen]);
        $this->assertEquals(1, count(self::$daviesService->getWarnings()));

        $this->insureWarningExists('/multiple open claims against policy/');
    }

    public function testSaveClaimsOpenDG()
    {
        $verifyTest = false;

        $policyOpen = static::createUserPolicy(true);
        $policyOpen->getUser()->setEmail(static::generateEmail('testSaveClaimsOpenDG', $this));
        $initialImei = $policyOpen->getImei();
        $claimOpen = new Claim();
        $claimOpen->setType(Claim::TYPE_LOSS);
        $claimOpen->setStatus(Claim::STATUS_APPROVED);
        $claimOpen->setNumber(self::getRandomPolicyNumber('TEST'));
        $claimOpen->setHandlingTeam(Claim::TEAM_DAVIES);
        $policyOpen->addClaim($claimOpen);

        $claimOpen2 = new Claim();
        $claimOpen2->setType(Claim::TYPE_LOSS);
        $claimOpen2->setNumber(self::getRandomPolicyNumber('TEST'));
        $claimOpen2->setStatus(Claim::STATUS_APPROVED);
        $claimOpen2->setReplacementImei(static::generateRandomImei());
        $claimOpen2->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);

        if (!$verifyTest) {
            $policyOpen->addClaim($claimOpen2);
        }

        static::$dm->persist($policyOpen->getUser());
        static::$dm->persist($policyOpen);
        static::$dm->persist($claimOpen);
        static::$dm->persist($claimOpen2);
        static::$dm->flush();
        self::$daviesService->clearErrors();
        self::$daviesService->clearWarnings();
        self::$daviesService->clearSoSureActions();

        $now = \DateTime::createFromFormat('U', time());
        $daviesOpen = new DaviesHandlerClaim();
        $daviesOpen->policyNumber = $policyOpen->getPolicyNumber();
        $daviesOpen->claimNumber = $claimOpen->getNumber();
        $daviesOpen->insuredName = $policyOpen->getUser()->getName();
        $daviesOpen->status = 'Open';
        $daviesOpen->lossDate = new \DateTime('2017-02-01');
        $daviesOpen->replacementImei = static::generateRandomImei();
        $daviesOpen->replacementReceivedDate = $now;
        $daviesOpen->replacementMake = 'foo';
        $daviesOpen->replacementModel = 'bar';
        $daviesOpen->phoneReplacementCost = 100;
        $daviesOpen->incurred = 100;
        $daviesOpen->initialSuspicion = false;
        $daviesOpen->finalSuspicion = false;
        $daviesOpen->lossDescription = 'foo bar';
        $daviesOpen->lossType = DaviesHandlerClaim::TYPE_LOSS;

        $this->assertEquals(0, count(self::$daviesService->getErrors()));
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->saveClaims(1, [$daviesOpen]);
        //print_r(self::$daviesService->getErrors());
        //print_r(self::$daviesService->getWarnings());
        $this->assertEquals(0, count(self::$daviesService->getErrors()));
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        $this->assertEquals(0, count(self::$daviesService->getSoSureActions()));

        $updatedPolicy = $this->assertPolicyExists(static::$container, $policyOpen);

        if ($verifyTest) {
            $this->assertEquals($daviesOpen->replacementImei, $updatedPolicy->getImei());
        } else {
            $this->assertEquals($initialImei, $updatedPolicy->getImei());
        }
    }

    public function testUpdateClaimNoFinalFlag()
    {

        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(static::getRandomPolicyNumber('TEST'));
        $policy->setPolicyTerms(static::$policyTerms);
        $policy->setPhone(self::getRandomPhone(self::$dm));

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_LOSS);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesHandlerClaim::STATUS_CLOSED;
        $daviesClaim->finalSuspicion = null;
        $daviesClaim->initialSuspicion = null;
        $daviesClaim->finalSuspicion = null;
        self::$daviesService->clearWarnings();
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->assertEquals(1, count(self::$daviesService->getWarnings()));
        $this->insureWarningExists('/finalSuspicion/');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Invalid replacement imei invalid
     */
    public function testValidateClaimInvalidImei()
    {
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(static::getRandomPolicyNumber('TEST'));
        $policy->setPolicyTerms(static::$policyTerms);
        $policy->setPhone(self::getRandomPhone(self::$dm));

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_SETTLED);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesHandlerClaim::STATUS_CLOSED;
        $daviesClaim->replacementImei = 'invalid';
        $daviesClaim->finalSuspicion = null;
        $daviesClaim->initialSuspicion = null;
        $daviesClaim->finalSuspicion = null;
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
    }

    public function testMissingLossDescription()
    {
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(static::getRandomPolicyNumber('TEST'));
        $policy->setPolicyTerms(static::$policyTerms);
        $policy->setPhone(self::getRandomPhone(self::$dm));

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_LOSS);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesHandlerClaim::STATUS_CLOSED;
        $daviesClaim->finalSuspicion = null;
        $daviesClaim->initialSuspicion = null;
        $daviesClaim->finalSuspicion = null;
        $daviesClaim->lossDescription = '1234';
        self::$daviesService->clearWarnings();
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->assertEquals(1, count(self::$daviesService->getWarnings()));
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
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->insuredName = 'Marko Marulic';
        $daviesClaim->initialSuspicion = null;
        $daviesClaim->finalSuspicion = null;

        self::$daviesService->clearWarnings();
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->assertEquals(1, count(self::$daviesService->getWarnings()));
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
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->insuredName = 'Marko Marulic';
        $daviesClaim->lossDescription = 'min length';
        $daviesClaim->initialSuspicion = false;
        $daviesClaim->finalSuspicion = null;

        self::$daviesService->clearWarnings();
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
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
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesHandlerClaim::STATUS_CLOSED;
        $daviesClaim->insuredName = 'Marko Marulic';
        $daviesClaim->lossDescription = 'min length';
        $daviesClaim->initialSuspicion = false;
        $daviesClaim->finalSuspicion = false;

        self::$daviesService->clearWarnings();
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
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
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->insuredName = 'Marko Marulic';
        $daviesClaim->initialSuspicion = null;
        $daviesClaim->finalSuspicion = null;

        self::$daviesService->clearWarnings();
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->assertEquals(1, count(self::$daviesService->getWarnings()));
        $this->insureWarningExists('/initialSuspicion/');
    }

    public function testUpdateClaimValidDaviesFinalFlagMissing()
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
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesHandlerClaim::STATUS_CLOSED;
        $daviesClaim->insuredName = 'Marko Marulic';
        $daviesClaim->initialSuspicion = false;
        $daviesClaim->finalSuspicion = null;

        self::$daviesService->clearWarnings();
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->assertEquals(1, count(self::$daviesService->getWarnings()));
        $this->insureWarningExists('/finalSuspicion/');
    }

    public function testSaveClaimsOpenClosed()
    {
        $daviesOpen = new DaviesHandlerClaim();
        $daviesOpen->policyNumber = self::getRandomPolicyNumber('TEST');
        $daviesOpen->claimNumber = 'a';
        $daviesOpen->status = 'Open';
        $daviesOpen->lossDate = new \DateTime('2017-01-01');

        $daviesClosed = new DaviesHandlerClaim();
        $daviesClosed->policyNumber = $daviesOpen->getPolicyNumber();
        $daviesClosed->claimNumber = 'a';
        $daviesClosed->status = 'Closed';
        $daviesClosed->lossDate = new \DateTime('2017-02-01');

        self::$daviesService->clearErrors();

        $this->assertEquals(0, count(self::$daviesService->getErrors()));
        self::$daviesService->saveClaims(1, [$daviesOpen, $daviesClosed]);
        $this->assertEquals(1, count(self::$daviesService->getErrors()));

        $this->insureErrorExists('/older then the closed claim/');
    }

    public function testSaveClaimsOpenClosedDb()
    {
        $policy1 = static::createUserPolicy(true);
        $policy1->getUser()->setEmail(static::generateEmail('testSaveClaimsOpenClosedDb-1', $this));
        $claim1 = new Claim();
        $claim1->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy1->addClaim($claim1);
        $claim1->setNumber(rand(1, 999999));
        $claim1->setType(Claim::TYPE_THEFT);

        static::$dm->persist($policy1->getUser());
        static::$dm->persist($policy1);
        static::$dm->persist($claim1);
        static::$dm->flush();

        $daviesOpen = new DaviesHandlerClaim();
        $daviesOpen->policyNumber = $policy1->getPolicyNumber();
        $daviesOpen->claimNumber = $claim1->getNumber();
        $daviesOpen->status = 'Open';
        $daviesOpen->lossDate = new \DateTime('2017-01-01');

        $this->assertEquals(0, count(self::$daviesService->getErrors()));
        self::$daviesService->saveClaims(1, [$daviesOpen]);

        $daviesClosed = new DaviesHandlerClaim();
        $daviesClosed->policyNumber = $daviesOpen->getPolicyNumber();
        $daviesClosed->claimNumber = 'a';
        $daviesClosed->status = 'Closed';
        $daviesClosed->lossDate = new \DateTime('2017-02-01');

        self::$daviesService->clearErrors();

        $this->assertEquals(0, count(self::$daviesService->getErrors()));
        self::$daviesService->saveClaims(1, [$daviesClosed]);
        // also missing claim number
        $this->assertEquals(1, count(self::$daviesService->getErrors()));

        $this->insureWarningExists('/older then the closed claim/');
    }

    public function testSaveClaimsClosedOpen()
    {
        $daviesOpen = new DaviesHandlerClaim();
        $daviesOpen->policyNumber = self::getRandomPolicyNumber('TEST');
        $daviesOpen->claimNumber = 'a';
        $daviesOpen->status = 'Open';
        $daviesOpen->lossDate = new \DateTime('2017-02-01');

        $daviesClosed = new DaviesHandlerClaim();
        $daviesClosed->policyNumber = $daviesOpen->getPolicyNumber();
        $daviesClosed->claimNumber = 'a';
        $daviesClosed->status = 'Closed';
        $daviesClosed->lossDate = new \DateTime('2017-01-01');

        self::$daviesService->clearErrors();

        $this->assertEquals(0, count(self::$daviesService->getErrors()));
        self::$daviesService->saveClaims(1, [$daviesOpen, $daviesClosed]);
        $this->assertEquals(1, count(self::$daviesService->getErrors()));
        $this->insureErrorDoesNotExist('/older then the closed claim/');
        $this->insureErrorExists('/Unable to locate claim/');
    }

    public function testSaveClaimsSaveException()
    {
        $policy1 = static::createUserPolicy(true);
        $policy1->getUser()->setEmail(static::generateEmail('testSaveClaimsSaveException-1', $this));
        $claim1 = new Claim();
        $claim1->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy1->addClaim($claim1);
        $claim1->setNumber('1');
        //$claim1->setType(Claim::TYPE_THEFT);

        $policy2 = static::createUserPolicy(true);
        $policy2->getUser()->setEmail(static::generateEmail('testSaveClaimsSaveException-2', $this));
        $claim2 = new Claim();
        $claim2->setType(Claim::TYPE_THEFT);
        $claim2->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy2->addClaim($claim2);
        $claim2->setNumber('2');

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
        $daviesOpen1 = new DaviesHandlerClaim();
        $daviesOpen1->claimNumber = '1';
        $daviesOpen1->policyNumber = $policy1->getPolicyNumber();
        $daviesOpen1->status = 'Open';
        $daviesOpen1->excess = 0;
        $daviesOpen1->reserved = 1;
        $daviesOpen1->riskPostCode = 'BX1 1LT';
        $daviesOpen1->insuredName = 'Foo Bar';
        //$daviesOpen1->lossType = DaviesHandlerClaim::TYPE_THEFT;

        // should be saved
        $daviesOpen2 = new DaviesHandlerClaim();
        $daviesOpen2->claimNumber = '2';
        $daviesOpen2->policyNumber = $policy2->getPolicyNumber();
        $daviesOpen2->status = 'Open';
        $daviesOpen2->excess = 0;
        $daviesOpen2->reserved = 2;
        $daviesOpen2->riskPostCode = 'BX1 1LT';
        $daviesOpen2->insuredName = 'Foo Bar';
        $daviesOpen2->lossType = DaviesHandlerClaim::TYPE_THEFT;

        self::$daviesService->clearErrors();

        $this->assertEquals(0, count(self::$daviesService->getErrors()));

        self::$daviesService->saveClaims(2, [$daviesOpen1, $daviesOpen2]);

        // print_r(self::$daviesService->getErrors());

        // Claims type does not match for claim 1 [Record import failed]
        $this->assertEquals(1, count(self::$daviesService->getErrors()));

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

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->policyNumber = 'TEST/2017/123456';

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $daviesClaim,
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
        $user->addPolicy($policy);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);
        $policy->setPolicyNumber('TEST/2017/123456');

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->policyNumber = 'TEST/2017/123456';
        $daviesClaim->replacementImei = $this->generateRandomImei();
        $daviesClaim->status = 'Closed';
        $daviesClaim->miStatus = 'Withdrawn';
        $daviesClaim->insuredName = 'Mr Foo Bar';

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $daviesClaim,
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
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        $policy->setPolicyNumber(self::getRandomPolicyNumber('TEST'));

        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->replacementImei = $this->generateRandomImei();
        $daviesClaim->status = DirectGroupHandlerClaim::STATUS_CLOSED;
        $daviesClaim->insuredName = 'Mr Foo Bar';
        $daviesClaim->lossDate = new \DateTime('2017-06-01');
        $daviesClaim->replacementReceivedDate = new \DateTime('2017-07-01');
        $daviesClaim->replacementMake = 'foo';
        $daviesClaim->replacementModel = 'bar';

        self::$daviesService->saveClaim($daviesClaim, false);
        //print_r(self::$directGroupService->getErrors());
        //print_r(self::$directGroupService->getWarnings());
        //print_r(self::$directGroupService->getSoSureActions());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Claim::class);
        $updatedClaim = $repo->find($claim->getId());
        $this->insureSoSureActionExists('/was previously closed/');
    }

    public function testValidateClaimDetailsMissingPhone()
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostcode('BX11LT');
        $user = new User();
        $user->setBillingAddress($address);
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $user->addPolicy($policy);
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber('TEST/2017/123456');

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->policyNumber = 'TEST/2017/123456';
        $daviesClaim->status = 'Open';
        $daviesClaim->miStatus = '';
        $daviesClaim->insuredName = 'Mr Foo Bar';
        $daviesClaim->lossDate = new \DateTime('2017-07-01');
        $daviesClaim->replacementReceivedDate = new \DateTime('2017-07-02');

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $daviesClaim,
            'replacement received date without a replacement make/model'
        );
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

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->policyNumber = 'TEST/2017/1234569';
        $daviesClaim->insuredName = 'Mr Bar Foo';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $daviesClaim,
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
        $policy->addClaim($claim);
        $policy->setPolicyNumber('TEST/2017/123456');
        $claim->setType(Claim::TYPE_LOSS);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->policyNumber = 'TEST/2017/123456';
        $daviesClaim->reserved = 1;
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->riskPostCode = 'se152sz';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        //$daviesClaim->type = DaviesClaim::TYPE_LOSS;

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->assertEquals(0, count(self::$daviesService->getErrors()));
    }

    public function testValidateClaimDetailsInvalidPolicyNumber()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->policyNumber = -1;
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $daviesClaim,
            'does not match policy number'
        );
    }

    public function testValidateClaimDetailsInvalidName()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr Patrick McAndrew';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $daviesClaim,
            'does not match expected insuredName'
        );
    }

    public function testValidateClaimDetailsInvalidReceivedDate()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->lossDate = new \DateTime('2017-07-01');
        $daviesClaim->replacementReceivedDate = new \DateTime('2017-06-01');
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $daviesClaim,
            'replacement received date prior to loss date'
        );
    }

    public function testValidateClaimDetailsInvalidPostcode()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->riskPostCode = 'se152sz';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureWarningExists('/does not match expected postcode/');
    }

    public function testValidateClaimDetailsInvalidPostcodeClosedRecent()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->riskPostCode = 'se152sz';
        $yesterday = \DateTime::createFromFormat('U', time());
        $yesterday = $yesterday->sub(new \DateInterval('P1D'));
        $daviesClaim->dateClosed = $yesterday;

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureWarningExists('/does not match expected postcode/');
    }

    public function testValidateClaimDetailsInvalidPostcodeClosedOld()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->riskPostCode = 'se152sz';
        $fiveDaysAgo = \DateTime::createFromFormat('U', time());
        $fiveDaysAgo = $fiveDaysAgo->sub(new \DateInterval('P5D'));
        $daviesClaim->dateClosed = $fiveDaysAgo;

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorDoesNotExist('/does not match expected postcode/');
    }

    public function testValidateClaimDetailsMissingReserved()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/does not have a reserved value/');
    }

    public function testValidateClaimDetailsIncorrectExcess()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = "Loss - From Pocket";
        $daviesClaim->excess = 50;
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/does not have the correct excess value/');

        self::$daviesService->clearErrors();

        $claim->setIgnoreWarningFlags(Claim::WARNING_FLAG_CLAIMS_INCORRECT_EXCESS);
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorDoesNotExist('/does not have the correct excess value/');
    }

    public function testValidateClaimDetailsNegativeExcess()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = "Loss - From Pocket";
        $daviesClaim->excess = -150;
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorDoesNotExist('/does not have the correct excess value/');

        $daviesClaim->replacementImei = $this->generateRandomImei();
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/does not have the correct excess value/');
    }

    public function testValidateClaimDetailsCorrectExcessPicsure()
    {
        $policy = static::createUserPolicy(true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = "Loss - From Pocket";
        $daviesClaim->excess = 70;
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorDoesNotExist('/does not have the correct excess value/');
    }

    public function testValidateClaimDetailsIncorrectExcessPicsureHigh()
    {
        $policy = static::createUserPolicy(true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = "Loss - From Pocket";
        $daviesClaim->excess = 150;
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/does not have the correct excess value/');
    }

    public function testValidateClaimDetailsIncorrectExcessPicsureLow()
    {
        $policy = static::createUserPolicy(true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = "Loss - From Pocket";
        $daviesClaim->excess = 70;
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 500;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/does not have the correct excess value/');
    }

    public function testValidateClaimDetailsClosedWithReserve()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesHandlerClaim::STATUS_CLOSED;
        $daviesClaim->lossType = "Loss - From Pocket";
        $daviesClaim->reserved = 10;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/still has a reserve fee/');
    }

    public function testValidateClaimDetailsReservedPresent()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 1;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorDoesNotExist('/does not have a reserved value/');
    }

    public function testValidateClaimDetailsIncurredPresent()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 1;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
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
        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 6.68;
        $daviesClaim->unauthorizedCalls = 1.01;
        $daviesClaim->accessories = 1.03;
        $daviesClaim->phoneReplacementCost = -50;
        $daviesClaim->transactionFees = 1.11;
        $daviesClaim->handlingFees = 1.19;
        $daviesClaim->reciperoFee = 1.27;
        $daviesClaim->excess = 6;
        $daviesClaim->reserved = 0;
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone';
        $daviesClaim->replacementReceivedDate = $twelveDaysAgo;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/does not have the correct phone replacement cost/');
    }

    public function testValidateClaimPhoneReplacementCostsCorrectTooRecent()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $fourDaysAgo = \DateTime::createFromFormat('U', time());
        $fourDaysAgo = $fourDaysAgo->sub(new \DateInterval('P4D'));
        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 6.68;
        $daviesClaim->unauthorizedCalls = 1.01;
        $daviesClaim->accessories = 1.03;
        $daviesClaim->phoneReplacementCost = -50;
        $daviesClaim->transactionFees = 1.11;
        $daviesClaim->handlingFees = 1.19;
        $daviesClaim->reciperoFee = 1.27;
        $daviesClaim->excess = 6;
        $daviesClaim->reserved = 0;
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone';
        $daviesClaim->replacementReceivedDate = $fourDaysAgo;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorDoesNotExist('/does not have the correct phone replacement cost/');
    }

    public function testValidateClaimDetailsIncurredCorrect()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 6.68;
        $daviesClaim->unauthorizedCalls = 1.01;
        $daviesClaim->accessories = 1.03;
        $daviesClaim->phoneReplacementCost = 1.07;
        $daviesClaim->transactionFees = 1.11;
        $daviesClaim->handlingFees = 1.19;
        $daviesClaim->reciperoFee = 1.27;
        $daviesClaim->excess = 6;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorDoesNotExist('/does not have the correct incurred value/');
    }

    public function testValidateClaimDetailsIncurredIncorrect()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 1;
        $daviesClaim->unauthorizedCalls = 1.01;
        $daviesClaim->accessories = 1.03;
        $daviesClaim->phoneReplacementCost = 1.07;
        $daviesClaim->transactionFees = 1.11;
        $daviesClaim->handlingFees = 1.19;
        $daviesClaim->reciperoFee = 1.27;
        $daviesClaim->excess = 6;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/does not have the correct incurred value/');
    }

    public function testValidateClaimDetailsIncurredIncorrectForFees()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 1;
        $daviesClaim->unauthorizedCalls = 1.01;
        $daviesClaim->accessories = 1.03;
        $daviesClaim->phoneReplacementCost = 1.07;
        $daviesClaim->transactionFees = 1.11;
        $daviesClaim->handlingFees = 1.19;
        $daviesClaim->reciperoFee = 1.27;
        $daviesClaim->excess = 1.5;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/does not have the correct incurred value/');
    }

    public function testValidateClaimDetailsReciperoFee()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $policy->addClaim($claim);
        $charge = new Charge();
        $charge->setAmount(0.90);
        $claim->addCharge($charge);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->reciperoFee = 1.08;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureFeesDoesNotExist('/does not have the correct recipero fee/');

        $daviesClaim->reciperoFee = 1.26;
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureFeesExists('/does not have the correct recipero fee/');
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

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 1;
        $daviesClaim->unauthorizedCalls = 1.01;
        $daviesClaim->accessories = 1.03;
        // replacement cost is bigger than initial price, should create warning
        $replacementCost = $policy->getPhone()->getInitialPrice() + 10;
        $daviesClaim->phoneReplacementCost = $replacementCost;
        $daviesClaim->transactionFees = 1.11;
        $daviesClaim->handlingFees = 1.19;
        $daviesClaim->reciperoFee = 1.27;
        $daviesClaim->excess = 6;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 8';
        $daviesClaim->replacementReceivedDate = new \DateTime('2016-01-01');
        // set ignore warning flag
        $claim->setIgnoreWarningFlags(Claim::WARNING_FLAG_CLAIMS_REPLACEMENT_COST_HIGHER);
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
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

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 1;
        $daviesClaim->unauthorizedCalls = 1.01;
        $daviesClaim->accessories = 1.03;

        // replacement cost is bigger than initial price
        $replacementCost = $policy->getPhone()->getInitialPrice() + 10;
        $daviesClaim->phoneReplacementCost = $replacementCost;
        $daviesClaim->transactionFees = 1.11;
        $daviesClaim->handlingFees = 1.19;
        $daviesClaim->reciperoFee = 1.27;
        $daviesClaim->excess = 6;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 8';
        $daviesClaim->replacementReceivedDate = new \DateTime('2016-01-01');
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);

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

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 1;
        $daviesClaim->unauthorizedCalls = 1.01;
        $daviesClaim->accessories = 1.03;

        // price is the same as initial price
        $replacementCost = $policy->getPhone()->getInitialPrice();
        $daviesClaim->phoneReplacementCost = $replacementCost;
        $daviesClaim->transactionFees = 1.11;
        $daviesClaim->handlingFees = 1.19;
        $daviesClaim->reciperoFee = 1.27;
        $daviesClaim->excess = 6;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 8';
        $daviesClaim->replacementReceivedDate = new \DateTime('2016-01-01');
        // all ignore warning flags are off
        $claim->clearIgnoreWarningFlags();
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        // we are not expecting warning
        $this->insureWarningDoesNotExist('/Device replacement cost/');
    }

    public function testValidateClaimDetailsReceivedDate()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setApprovedDate(new \DateTime('2016-01-02'));
        $policy->addClaim($claim);

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 1;
        $daviesClaim->unauthorizedCalls = 1.01;
        $daviesClaim->accessories = 1.03;
        $daviesClaim->phoneReplacementCost = 1.07;
        $daviesClaim->transactionFees = 1.11;
        $daviesClaim->handlingFees = 1.19;
        $daviesClaim->reciperoFee = 1.27;
        $daviesClaim->excess = 6;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 8';
        $daviesClaim->replacementReceivedDate = new \DateTime('2016-01-01');

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
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

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 1;
        $daviesClaim->unauthorizedCalls = 1.01;
        $daviesClaim->accessories = 1.03;
        $daviesClaim->phoneReplacementCost = 1.07;
        $daviesClaim->transactionFees = 1.11;
        $daviesClaim->handlingFees = 1.19;
        $daviesClaim->reciperoFee = 1.27;
        $daviesClaim->excess = 6;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 8';
        $daviesClaim->replacementReceivedDate = new \DateTime('2016-01-01');

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/should be closed. Replacement was delivered more than/');

        self::$daviesService->clearErrors();

        $daviesClaim->replacementReceivedDate = new \DateTime('-20 days');
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
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

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 1;
        $daviesClaim->unauthorizedCalls = 1.01;
        $daviesClaim->accessories = 1.03;
        $daviesClaim->phoneReplacementCost = 0;
        $daviesClaim->transactionFees = 1.11;
        $daviesClaim->handlingFees = 1.19;
        $daviesClaim->reciperoFee = 1.27;
        $daviesClaim->excess = 6;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        //$daviesClaim->replacementMake = 'Apple';
        //$daviesClaim->replacementModel = 'iPhone 8';
        //$daviesClaim->replacementReceivedDate = new \DateTime('2016-01-01');

        $daviesClaim->status = 'closed';
        $daviesClaim->miStatus = DaviesHandlerClaim::MISTATUS_WITHDRAWN;
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureSoSureActionExists('/previously approved, however is now withdrawn/');
        $this->insureSoSureActionDoesNotExist('/previously approved, however no longer appears to be/');
        $this->insureErrorDoesNotExist('/the replacement data not recorded/');
        $this->insureErrorDoesNotExist('/received date/');
        $this->insureErrorDoesNotExist('/imei/');
        $this->insureErrorDoesNotExist('/phone/');

        self::$daviesService->clearErrors();
        self::$daviesService->clearSoSureActions();

        $daviesClaim->status = 'open';
        $daviesClaim->miStatus = null;
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureSoSureActionExists('/previously approved, however no longer appears to be/');
        $this->insureErrorDoesNotExist('/the replacement data not recorded/');
        $this->insureErrorDoesNotExist('/received date/');
        $this->insureErrorDoesNotExist('/imei/');
        $this->insureErrorDoesNotExist('/; phone/');

        self::$daviesService->clearErrors();
        self::$daviesService->clearSoSureActions();

        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 8';
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureSoSureActionExists('/previously approved, however no longer appears to be/');
        $this->insureErrorDoesNotExist('/the replacement data not recorded/');
        $this->insureErrorDoesNotExist('/received date/');
        $this->insureErrorDoesNotExist('/imei/');
        $this->insureErrorDoesNotExist('/; phone/');

        self::$daviesService->clearErrors();
        self::$daviesService->clearSoSureActions();

        $daviesClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/the replacement data not recorded/');
        $this->insureErrorDoesNotExist('/received date/');
        $this->insureErrorExists('/imei/');
        $this->insureErrorDoesNotExist('/; phone/');

        self::$daviesService->clearErrors();
        self::$daviesService->clearSoSureActions();

        $daviesClaim->replacementImei = $this->generateRandomImei();
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorDoesNotExist('/the replacement data not recorded/');
        $this->insureErrorDoesNotExist('/received date/');
        $this->insureErrorDoesNotExist('/imei/');
        $this->insureErrorDoesNotExist('/; phone/');

        $daviesClaim->replacementImei = 'NA - repaired';
        $daviesClaim->checkReplacementRepaired();
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
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

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = 1;

        self::$daviesService->postValidateClaimDetails($claim, $daviesClaim);
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
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = (string) $claim->getNumber();
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        $daviesClaim->replacementReceivedDate = new \DateTime('2016-01-02');
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';

        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $mailer->expects($this->never())->method('send');
        self::$daviesService->setMailerMailer($mailer);

        static::$daviesService->saveClaim($daviesClaim, false);
        $this->assertNotNull($claim->getApprovedDate());
        $this->assertEquals(new \DateTime('2016-01-01'), $claim->getApprovedDate());
    }


    public function testSaveClaimsRepudiatedEmailLossTest()
    {
        $policy = static::createUserPolicy(true);
        $policy->setStatus(Policy::STATUS_UNPAID);

        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsRepudiatedEmailLossTest', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = (string) $claim->getNumber();
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        $daviesClaim->replacementReceivedDate = new \DateTime('2016-01-02');
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        $daviesClaim->miStatus = DaviesHandlerClaim::MISTATUS_REPUDIATED;

        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $mailer->expects($this->once())->method('send');
        self::$daviesService->setMailerMailer($mailer);

        static::$daviesService->saveClaim($daviesClaim, false);
        $this->assertNotNull($claim->getApprovedDate());
        $this->assertEquals(new \DateTime('2016-01-01'), $claim->getApprovedDate());

        // cancelled should not trigger
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $mailer->expects($this->never())->method('send');
        self::$daviesService->setMailerMailer($mailer);

        static::$daviesService->saveClaim($daviesClaim, false);
        $this->assertNotNull($claim->getApprovedDate());
        $this->assertEquals(new \DateTime('2016-01-01'), $claim->getApprovedDate());
    }

    public function testSaveClaimsRepudiatedEmailDamageTest()
    {
        $policy = static::createUserPolicy(true);
        $policy->setStatus(Policy::STATUS_UNPAID);

        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsRepudiatedEmailDamageTest', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_DAMAGE);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $policy->addClaim($claim);
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = (string) $claim->getNumber();
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_DAMAGE;
        $daviesClaim->replacementReceivedDate = new \DateTime('2016-01-02');
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        $daviesClaim->miStatus = DaviesHandlerClaim::MISTATUS_REPUDIATED;

        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $mailer->expects($this->never())->method('send');
        self::$daviesService->setMailerMailer($mailer);

        static::$daviesService->saveClaim($daviesClaim, false);
        $this->assertNotNull($claim->getApprovedDate());
        $this->assertEquals(new \DateTime('2016-01-01'), $claim->getApprovedDate());
    }

    public function testSaveClaimValidationError()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimValidationError', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = (string) $claim->getNumber();
        $daviesClaim->totalIncurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        $daviesClaim->replacementReceivedDate = new \DateTime('2016-01-02');
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        static::$daviesService->saveClaim($daviesClaim, false);
        $this->assertEquals(new \DateTime('2016-01-01'), $claim->getApprovedDate());
        $this->assertEquals(0, $claim->getIncurred());

        // fail validation
        $daviesClaim->location = '☺';
        $daviesClaim->totalIncurred = 1;
        static::$daviesService->saveClaim($daviesClaim, false);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Claim::class);
        $updatedClaim = $repo->find($claim->getId());
        $this->assertEquals(0, $updatedClaim->getIncurred());

        $daviesClaim->location = null;
        $daviesClaim->totalIncurred = 2;
        static::$daviesService->saveClaim($daviesClaim, false);

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
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->phoneReplacementCost = -70;
        $daviesClaim->incurred = -70;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        static::$daviesService->saveClaim($daviesClaim, false);
        $this->assertEquals(Claim::STATUS_APPROVED, $claim->getStatus());
        $now = \DateTime::createFromFormat('U', time());
        $yesterday = $this->subBusinessDays($now, 1);
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
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        static::$daviesService->saveClaim($daviesClaim, false);
        $now = \DateTime::createFromFormat('U', time());
        $yesterday = $this->subBusinessDays($now, 1);
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
        foreach (self::$daviesService->getWarnings() as $warning) {
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
        foreach (self::$daviesService->getErrors() as $error) {
            $matches = preg_grep($errorRegEx, $error);
            if (count($matches) > 0) {
                $foundMatch = true;
            }
        }
        if ($exists) {
            $this->assertTrue(
                $foundMatch,
                sprintf('did not find %s in %s', $errorRegEx, json_encode(self::$daviesService->getErrors()))
            );
        } else {
            $this->assertFalse(
                $foundMatch,
                sprintf('found %s in %s', $errorRegEx, json_encode(self::$daviesService->getErrors()))
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
        foreach (self::$daviesService->getSoSureActions() as $error) {
            $matches = preg_grep($errorRegEx, $error);
            if (count($matches) > 0) {
                $foundMatch = true;
            }
        }
        if ($exists) {
            $this->assertTrue(
                $foundMatch,
                sprintf('did not find %s in %s', $errorRegEx, json_encode(self::$daviesService->getSoSureActions()))
            );
        } else {
            $this->assertFalse(
                $foundMatch,
                sprintf('found %s in %s', $errorRegEx, json_encode(self::$daviesService->getSoSureActions()))
            );
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
        foreach (self::$daviesService->getFees() as $fee) {
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

    private function validateClaimsDetailsThrowsException($claim, $daviesClaim, $message)
    {
        $exception = false;
        try {
            self::$daviesService->validateClaimDetails($claim, $daviesClaim);
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
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 10;
        $daviesClaim->initialSuspicion = false;
        $daviesClaim->finalSuspicion = false;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        $daviesClaim->lossDescription = 'min length';
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        $daviesClaim->replacementImei = 'invalid';
        $daviesClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $this->assertFalse(static::$daviesService->saveClaim($daviesClaim, false));
        $this->assertEquals(
            0,
            count(self::$daviesService->getErrors()),
            json_encode(self::$daviesService->getErrors())
        );
        $this->assertEquals(
            0,
            count(self::$daviesService->getWarnings()),
            json_encode(self::$daviesService->getWarnings())
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
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 10;
        $daviesClaim->initialSuspicion = false;
        $daviesClaim->finalSuspicion = false;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        $daviesClaim->lossDescription = 'min length';
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        $daviesClaim->replacementImei = $this->generateRandomImei();
        $daviesClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $this->assertTrue(static::$daviesService->saveClaim($daviesClaim, false));
        $this->assertEquals(
            0,
            count(self::$daviesService->getErrors()),
            json_encode(self::$daviesService->getErrors())
        );
        $this->assertEquals(
            0,
            count(self::$daviesService->getWarnings()),
            json_encode(self::$daviesService->getWarnings())
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
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 10;
        $daviesClaim->initialSuspicion = false;
        $daviesClaim->finalSuspicion = false;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        // fromArray will transform replacementImei = 'Unable to obtain' to a null value and add to unobtainableFields
        // $daviesClaim->replacementImei = 'Unable to obtain';
        $daviesClaim->unobtainableFields[] = 'replacementImei';

        $daviesClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $this->assertTrue(static::$daviesService->saveClaim($daviesClaim, false));
        $this->assertEquals(
            0,
            count(self::$daviesService->getErrors()),
            json_encode(self::$daviesService->getErrors())
        );
        $this->assertEquals(
            1,
            count(self::$daviesService->getWarnings()),
            json_encode(self::$daviesService->getWarnings())
        );
        $this->insureWarningExists('/does not have a replacement IMEI/');
    }

    public function testReportMissingClaims()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testReportMissingClaims', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);

        $yesterday = \DateTime::createFromFormat('U', time());
        $yesterday = $yesterday->sub(new \DateInterval('P1D'));
        $claim->setRecordedDate($yesterday);

        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        //$daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesHandlerClaim::STATUS_CLOSED;
        $daviesClaim->finalSuspicion = null;
        $daviesClaim->initialSuspicion = null;
        $daviesClaim->finalSuspicion = null;
        $daviesClaim->lossDescription = '1234';
        $daviesClaim->claimNumber = rand(1, 999999);

        $daviesClaims = array($daviesClaim);

        self::$daviesService->reportMissingClaims($daviesClaims);
        // print_r(self::$daviesService->getErrors());

        $this->insureErrorExists('/'. $claim->getNumber() .'/');
        $this->insureErrorExists('/'. preg_quote($claim->getPolicy()->getPolicyNumber(), '/') .'/');
    }

    public function testSaveClaimsNoClaimsFound()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsNoClaimsFound', $this));
        $claim1 = new Claim();
        $claim1->setType(Claim::TYPE_LOSS);
        $claim1->setStatus(Claim::STATUS_APPROVED);
        $claim1->setNumber($this->getRandomClaimNumber());
        $claim1->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim1);

        $yesterday = \DateTime::createFromFormat('U', time());
        $yesterday = $yesterday->sub(new \DateInterval('P1D'));
        $claim2 = new Claim();
        $claim2->setRecordedDate($yesterday);
        $claim2->setType(Claim::TYPE_LOSS);
        $claim2->setStatus(Claim::STATUS_APPROVED);
        $claim2->setNumber($this->getRandomClaimNumber());
        $claim2->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim2);

        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim1);
        static::$dm->persist($claim2);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->policyNumber = $claim1->getPolicy()->getPolicyNumber();
        $daviesClaim->claimNumber = $claim2->getNumber();
        $daviesClaim->insuredName = 'foo bar';
        $daviesClaim->initialSuspicion = false;
        $daviesClaim->finalSuspicion = false;
        $daviesClaim->status = DaviesHandlerClaim::STATUS_CLOSED;
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        $daviesClaim->replacementImei = '123 Bx11lt';
        $daviesClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $daviesClaim->phoneReplacementCost = 100;
        $daviesClaim->incurred = 100;
        $daviesClaims = array($daviesClaim);

        static::$daviesService->saveClaims('', $daviesClaims);
        $this->insureErrorDoesNotExist('/'.$claim1->getNumber().'/');
        $this->insureErrorDoesNotExist('/'.$claim2->getNumber().'/');

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->policyNumber = $claim1->getPolicy()->getPolicyNumber();
        $daviesClaim->claimNumber = $this->getRandomClaimNumber();
        $daviesClaim->status = DaviesHandlerClaim::STATUS_CLOSED;
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        $daviesClaim->insuredName = 'foo bar';
        $daviesClaim->initialSuspicion = 'no';
        $daviesClaim->finalSuspicion = 'no';
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        $daviesClaim->replacementImei = '123 Bx11lt';
        $daviesClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $daviesClaims = array($daviesClaim);

        static::$daviesService->saveClaims('', $daviesClaims);
        $this->insureErrorDoesNotExist('/'.$claim1->getNumber().'/');
        $this->insureErrorExists('/'.$claim2->getNumber().'/');
    }

    public function testSaveClaimMissingReplacementImeiPhone()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimMissingReplacementImeiPhone', $this));
        $claim = new Claim();
        $claim->setNumber($this->getRandomClaimNumber());
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->incurred = 150;
        $daviesClaim->reserved = 0;
        $daviesClaim->excess = 150;
        $daviesClaim->initialSuspicion = false;
        $daviesClaim->finalSuspicion = false;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_CLOSED;
        $daviesClaim->miStatus = DaviesHandlerClaim::MISTATUS_SETTLED;
        $daviesClaim->lossDescription = 'min length';
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        //$daviesClaim->replacementImei = $this->generateRandomImei();
        $daviesClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $this->assertTrue(static::$daviesService->saveClaim($daviesClaim, false));
        $this->assertEquals(
            1,
            count(self::$daviesService->getErrors()[$claim->getNumber()]),
            json_encode(self::$daviesService->getErrors())
        );
        $this->assertEquals(
            0,
            count(self::$daviesService->getWarnings()),
            json_encode(self::$daviesService->getWarnings())
        );
        $this->insureErrorExists('/settled without a replacement imei/');
        $this->insureSoSureActionExists('/settled without a replacement phone/');

        self::$daviesService->clearErrors();
        self::$daviesService->clearWarnings();
        self::$daviesService->clearFees();
        self::$daviesService->clearSoSureActions();

        $daviesClaim->replacementImei = $this->generateRandomImei();
        $this->assertTrue(static::$daviesService->saveClaim($daviesClaim, false));
        /*
        $this->assertEquals(
            1,
            count(self::$daviesService->getErrors()[$claim->getNumber()]),
            json_encode(self::$daviesService->getErrors())
        );
        */
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
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesHandlerClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->incurred = 150;
        $daviesClaim->reserved = 0;
        $daviesClaim->excess = 150;
        $daviesClaim->initialSuspicion = false;
        $daviesClaim->finalSuspicion = false;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesHandlerClaim::STATUS_CLOSED;
        $daviesClaim->miStatus = DaviesHandlerClaim::MISTATUS_SETTLED;
        $daviesClaim->lossDescription = 'min length';
        $daviesClaim->lossType = DaviesHandlerClaim::TYPE_LOSS;
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        //$daviesClaim->replacementImei = $this->generateRandomImei();
        $daviesClaim->replacementReceivedDate = \DateTime::createFromFormat('U', time());
        $this->assertTrue(static::$daviesService->saveClaim($daviesClaim, false));
        $this->assertEquals(
            1,
            count(self::$daviesService->getErrors()[$claim->getNumber()]),
            json_encode(self::$daviesService->getErrors())
        );
        $this->assertEquals(
            0,
            count(self::$daviesService->getWarnings()),
            json_encode(self::$daviesService->getWarnings())
        );
        $this->insureErrorDoesNotExist('/settled without a replacement phone/');
    }
}
