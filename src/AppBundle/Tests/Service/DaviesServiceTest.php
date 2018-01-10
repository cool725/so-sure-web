<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\Policy;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use AppBundle\Document\User;
use AppBundle\Document\Charge;
use AppBundle\Document\DateTrait;

use AppBundle\Classes\DaviesClaim;

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
    protected static $dm;
    protected static $daviesService;
    protected static $phone;
    protected static $phoneA;
    protected static $phoneB;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$daviesService = self::$container->get('app.davies');

        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 6', 'memory' => 64]);
        self::$phoneA = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phoneB = $phoneRepo->findOneBy(['devices' => 'A0001', 'memory' => 64]);
    }

    public function setUp()
    {
        self::$daviesService->clearErrors();
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
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

        $davies = new DaviesClaim();

        self::$daviesService->updatePolicy($claim, $davies, false);
        $this->assertEquals($imeiOld, $policy->getImei(), false);
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        $claimB = new Claim();
        $claimB->setReplacementPhone(self::$phoneB);
        $claimB->setReplacementImei($imeiNew);
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claimB);

        $daviesB = new DaviesClaim();
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

        $davies = new DaviesClaim();

        self::$daviesService->updatePolicy($claim, $davies, false);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        $claim->setStatus(Claim::STATUS_SETTLED);

        $claimB = new Claim();
        $claimB->setReplacementPhone(self::$phoneB);
        $claimB->setReplacementImei($imeiNew);
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claimB);

        $daviesB = new DaviesClaim();
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

        $davies = new DaviesClaim();

        self::$daviesService->updatePolicy($claim, $davies, false);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        $claimB = new Claim();
        $claimB->setReplacementPhone(self::$phoneB);
        $claimB->setReplacementImei($imeiNew);
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claimB);

        $daviesB = new DaviesClaim();
        $daviesB->replacementMake = 'Apple';
        $daviesB->replacementModel = 'iPhone 4';

        self::$daviesService->updatePolicy($claimB, $daviesB, true);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneB->getId(), $policy->getPhone()->getId());

        // Rerunning old settled claim should keep the newer imei
        $this->assertEquals(Claim::STATUS_APPROVED, $claim->getStatus());
        self::$daviesService->updatePolicy($claim, $davies, true);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneB->getId(), $policy->getPhone()->getId());
    }

    public function testGetPolicyNumber()
    {
        $davies = new DaviesClaim();
        $davies->policyNumber = 'TEST/2017/12345';
        $this->assertEquals('TEST/2017/12345', $davies->getPolicyNumber());

        $davies->policyNumber = 'number TEST/2017/12345';
        $this->assertEquals('TEST/2017/12345', $davies->getPolicyNumber());

        $davies->policyNumber = 'TEST/2017/1A2345';
        $this->assertNull($davies->getPolicyNumber());
    }

    public function testSaveClaimsClosed()
    {
        $davies = new DaviesClaim();
        $davies->policyNumber = '1';
        $davies->status = 'Closed';

        $daviesOpen = new DaviesClaim();
        $daviesOpen->policyNumber = '1';
        $daviesOpen->status = 'Open';

        self::$daviesService->saveClaims(1, [$davies, $davies]);
        self::$daviesService->saveClaims(1, [$davies, $daviesOpen]);
    }

    public function testSaveClaimsOpen()
    {
        $daviesOpen = new DaviesClaim();
        $daviesOpen->policyNumber = '1';
        $daviesOpen->status = 'Open';

        self::$daviesService->clearErrors();

        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->saveClaims(1, [$daviesOpen, $daviesOpen]);
        $this->assertEquals(1, count(self::$daviesService->getWarnings()));

        $this->insureWarningExists('/multiple open claims against policy/');
    }

    public function testUpdateClaimNoFinalFlag()
    {

        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId('1');

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_SETTLED);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesClaim::STATUS_CLOSED;
        $daviesClaim->finalSuspicion = null;
        $daviesClaim->initialSuspicion = null;
        $daviesClaim->finalSuspicion = null;
        self::$daviesService->clearWarnings();
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->assertEquals(1, count(self::$daviesService->getWarnings()));
        $this->insureWarningExists('/finalSuspicion/');
    }

    public function testUpdateClaimNoInitialFlag()
    {

        $user = new User();
        $user->setFirstName('Marko');
        $user->setLastName('Marulic');
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId('1');
        $policy->setUser($user);

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_INREVIEW);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
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
        $policy->setId('1');
        $policy->setUser($user);

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_INREVIEW);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
        $daviesClaim->insuredName = 'Marko Marulic';
        $daviesClaim->initialSuspicion = 'no';
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
        $policy->setId('1');
        $policy->setUser($user);

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setNumber(time());
        $claim->setStatus(Claim::STATUS_APPROVED);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->status = DaviesClaim::STATUS_CLOSED;
        $daviesClaim->insuredName = 'Marko Marulic';
        $daviesClaim->initialSuspicion = 'no';
        $daviesClaim->finalSuspicion = 'no';

        self::$daviesService->clearWarnings();
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->assertEquals(0, count(self::$daviesService->getWarnings()));
    }



    private function getRandomPolicyNumber()
    {
        return sprintf('TEST/2017/%6d', rand(1, 999999));
    }

    public function testSaveClaimsOpenClosed()
    {
        $daviesOpen = new DaviesClaim();
        $daviesOpen->policyNumber = $this->getRandomPolicyNumber();
        $daviesOpen->claimNumber = 'a';
        $daviesOpen->status = 'Open';
        $daviesOpen->lossDate = new \DateTime('2017-01-01');

        $daviesClosed = new DaviesClaim();
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

    public function testSaveClaimsClosedOpen()
    {
        $daviesOpen = new DaviesClaim();
        $daviesOpen->policyNumber = $this->getRandomPolicyNumber();
        $daviesOpen->claimNumber = 'a';
        $daviesOpen->status = 'Open';
        $daviesOpen->lossDate = new \DateTime('2017-02-01');

        $daviesClosed = new DaviesClaim();
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
        $policy1->addClaim($claim1);
        $claim1->setNumber('1');
        $claim1->setType(Claim::TYPE_THEFT);

        $policy2 = static::createUserPolicy(true);
        $policy2->getUser()->setEmail(static::generateEmail('testSaveClaimsSaveException-2', $this));
        $claim2 = new Claim();
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

        $daviesOpen1 = new DaviesClaim();
        $daviesOpen1->claimNumber = '1';
        $daviesOpen1->policyNumber = $policy1->getPolicyNumber();
        $daviesOpen1->status = 'Open';
        $daviesOpen1->excess = 0;
        $daviesOpen1->reserved = 1;
        $daviesOpen1->riskPostCode = 'BX1 1LT';
        $daviesOpen1->insuredName = 'Foo Bar';
        $daviesOpen1->type = DaviesClaim::TYPE_LOSS;

        $daviesOpen2 = new DaviesClaim();
        $daviesOpen2->claimNumber = '2';
        $daviesOpen2->policyNumber = $policy2->getPolicyNumber();
        $daviesOpen2->status = 'Open';
        $daviesOpen2->excess = 0;
        $daviesOpen2->reserved = 2;
        $daviesOpen2->riskPostCode = 'BX1 1LT';
        $daviesOpen2->insuredName = 'Foo Bar';
        $daviesOpen2->type = DaviesClaim::TYPE_LOSS;

        self::$daviesService->clearErrors();

        $this->assertEquals(0, count(self::$daviesService->getErrors()));

        self::$daviesService->saveClaims(2, [$daviesOpen1, $daviesOpen2]);

        // print_r(self::$daviesService->getErrors());

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

        $daviesClaim = new DaviesClaim();
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
        $address->setPostCode('BX11LT');
        $user = new User();
        $user->setBillingAddress($address);
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $user->addPolicy($policy);
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber('TEST/2017/123456');

        $daviesClaim = new DaviesClaim();
        $daviesClaim->policyNumber = 'TEST/2017/123456';
        $daviesClaim->replacementImei = '123';
        $daviesClaim->status = 'Closed';
        $daviesClaim->miStatus = 'Withdrawn';
        $daviesClaim->insuredName = 'Mr Foo Bar';

        $this->validateClaimsDetailsThrowsException(
            $claim,
            $daviesClaim,
            'replacement IMEI Number, yet has a withdrawn/declined status'
        );
    }

    public function testValidateClaimDetailsMissingPhone()
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostCode('BX11LT');
        $user = new User();
        $user->setBillingAddress($address);
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $user->addPolicy($policy);
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber('TEST/2017/123456');

        $daviesClaim = new DaviesClaim();
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

    public function testValidateClaimDetailsReplacementDateMissingImei()
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostCode('BX11LT');
        $user = new User();
        $user->setBillingAddress($address);
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $user->addPolicy($policy);
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber('TEST/2017/123456');

        $daviesClaim = new DaviesClaim();
        $daviesClaim->policyNumber = 'TEST/2017/123456';
        $daviesClaim->status = 'Open';
        $daviesClaim->miStatus = '';
        $daviesClaim->insuredName = 'Mr Foo Bar';
        $daviesClaim->lossDate = new \DateTime('2017-07-01');
        $daviesClaim->replacementReceivedDate = new \DateTime('2017-07-02');
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 8';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/replacement received date without a replacement imei/');
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

        $daviesClaim = new DaviesClaim();
        $daviesClaim->policyNumber = 'TEST/2017/1234569';
        $daviesClaim->insuredName = 'Mr Bar Foo';
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;

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
        $address->setPostCode('se152sz');
        $user = new User();
        $user->setBillingAddress($address);
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber('TEST/2017/123456');

        $daviesClaim = new DaviesClaim();
        $daviesClaim->policyNumber = 'TEST/2017/123456';
        $daviesClaim->reserved = 1;
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->riskPostCode = 'se152sz';
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
        $daviesClaim->type = DaviesClaim::TYPE_LOSS;

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->assertEquals(0, count(self::$daviesService->getErrors()));
    }

    public function testValidateClaimDetailsInvalidPolicyNumber()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->policyNumber = -1;
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;

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

        $daviesClaim = new DaviesClaim();
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr Patrick McAndrew';
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;

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

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->lossDate = new \DateTime('2017-07-01');
        $daviesClaim->replacementReceivedDate = new \DateTime('2017-06-01');
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;

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
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->riskPostCode = 'se152sz';
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureWarningExists('/does not match expected postcode/');
    }

    public function testValidateClaimDetailsInvalidPostcodeClosedRecent()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->riskPostCode = 'se152sz';
        $yesterday = new \DateTime();
        $yesterday = $yesterday->sub(new \DateInterval('P1D'));
        $daviesClaim->dateClosed = $yesterday;

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureWarningExists('/does not match expected postcode/');
    }

    public function testValidateClaimDetailsInvalidPostcodeClosedOld()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->riskPostCode = 'se152sz';
        $fiveDaysAgo = new \DateTime();
        $fiveDaysAgo = $fiveDaysAgo->sub(new \DateInterval('P5D'));
        $daviesClaim->dateClosed = $fiveDaysAgo;

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorDoesNotExist('/does not match expected postcode/');
    }

    public function testValidateClaimDetailsMissingReserved()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/does not have a reserved value/');
    }

    public function testValidateClaimDetailsIncorrectExcess()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
        $daviesClaim->lossType = "Loss - From Pocket";
        $daviesClaim->excess = 50;
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/does not have the correct excess value/');
    }

    public function testValidateClaimDetailsClosedWithReserve()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesClaim::STATUS_CLOSED;
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
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
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
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->incurred = 1;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorDoesNotExist('/does not have a reserved value/');
    }

    public function testValidateClaimDetailsIncurredCorrect()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
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
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
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

    public function testValidateClaimDetailsReciperoFee()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $policy->addClaim($claim);
        $charge = new Charge();
        $charge->setAmount(0.90);
        $claim->addCharge($charge);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = 1;
        $daviesClaim->status = 'open';
        $daviesClaim->reciperoFee = 1.08;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorDoesNotExist('/does not have the correct recipero fee/');

        $daviesClaim->reciperoFee = 1.26;
        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/does not have the correct recipero fee/');
    }

    public function testValidateClaimDetailsReceivedDate()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setApprovedDate(new \DateTime('2016-01-02'));
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
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
        // 3 months!
        $claim->setApprovedDate(new \DateTime('2016-04-01'));
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
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

    public function testPostValidateClaimDetailsReceivedDate()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setApprovedDate(new \DateTime('2016-01-02'));
        $claim->setReplacementReceivedDate(new \DateTime('2016-01-01'));
        $policy->addClaim($claim);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = 1;

        self::$daviesService->postValidateClaimDetails($claim, $daviesClaim);
        $this->insureErrorExists('/has an approved date/');
    }

    public function testSaveClaimsReplacementDate()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsReplacementDate', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = (string) $claim->getNumber();
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesClaim::TYPE_LOSS;
        $daviesClaim->replacementReceivedDate = new \DateTime('2016-01-02');
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
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
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = (string) $claim->getNumber();
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesClaim::TYPE_LOSS;
        $daviesClaim->replacementReceivedDate = new \DateTime('2016-01-02');
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        static::$daviesService->saveClaim($daviesClaim, false);
        $this->assertEquals(new \DateTime('2016-01-01'), $claim->getApprovedDate());
        $this->assertEquals(0, $claim->getIncurred());

        // fail validation
        $daviesClaim->location = '☺';
        $daviesClaim->incurred = 1;
        static::$daviesService->saveClaim($daviesClaim, false);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Claim::class);
        $updatedClaim = $repo->find($claim->getId());
        $this->assertEquals(0, $updatedClaim->getIncurred());

        $daviesClaim->location = null;
        $daviesClaim->incurred = 2;
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
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->phoneReplacementCost = -70;
        $daviesClaim->incurred = -70;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesClaim::TYPE_LOSS;
        static::$daviesService->saveClaim($daviesClaim, false);
        $this->assertEquals(Claim::STATUS_APPROVED, $claim->getStatus());
        $now = new \DateTime();
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
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesClaim::TYPE_LOSS;
        static::$daviesService->saveClaim($daviesClaim, false);
        $now = new \DateTime();
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
        $address->setPostCode('BX11LT');
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

    public function testSaveClaimsInvalidReplacementImei()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testSaveClaimsInvalidReplacementImei', $this));
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($claim);
        static::$dm->flush();

        $daviesClaim = new DaviesClaim();
        $daviesClaim->claimNumber = $claim->getNumber();
        $daviesClaim->incurred = 0;
        $daviesClaim->reserved = 0;
        $daviesClaim->policyNumber = $policy->getPolicyNumber();
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->status = DaviesClaim::STATUS_OPEN;
        $daviesClaim->lossType = DaviesClaim::TYPE_LOSS;
        $daviesClaim->replacementMake = 'Apple';
        $daviesClaim->replacementModel = 'iPhone 4';
        $daviesClaim->replacementImei = '123 Bx11lt';
        $daviesClaim->replacementReceivedDate = new \DateTime();
        $this->assertFalse(static::$daviesService->saveClaim($daviesClaim, false));
    }
}
