<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\BankAccount;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\DateTrait;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Cashback;
use AppBundle\Document\User;
use AppBundle\Service\BacsService;
use AppBundle\Service\InvitationService;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\Reward;
use AppBundle\Document\SCode;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\PolicyDiscountPayment;
use AppBundle\Document\Payment\PotRewardPayment;
use AppBundle\Document\Payment\SoSurePotRewardPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\CurrencyTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Tests\UserClassTrait;
use AppBundle\Classes\Salva;
use AppBundle\Document\File\ImeiFile;
use AppBundle\Document\File\PicSureFile;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Document\\PhonePolicyTest
 */
class PhonePolicyFunctionalTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use UserClassTrait;
    use DateTrait;

    protected static $container;
    protected static $invitationService;
    /** @var DocumentManager */
    protected static $dm;
    /** @var BacsService */
    protected static $bacsService;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 6s', 'memory' => 64]);
        /** @var InvitationService invitationService */
        $invitationService = self::$container->get('app.invitation');
        $invitationService->setDebug(true);
        self::$invitationService = $invitationService;
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');

        /** @var BacsService $bacsService */
        $bacsService = self::$container->get('app.bacs');
        self::$bacsService = $bacsService;
    }

    public function setUp()
    {
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
    }

    public function tearDown()
    {
        self::$dm->clear();
    }

    public function testStatusUpdated()
    {
        $policy = new SalvaPhonePolicy();
        $this->assertNull($policy->getStatusUpdated());
        $now = \DateTime::createFromFormat('U', time());
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $this->assertEquals($now, $policy->getStatusUpdated(), '', 1);
        sleep(1);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $this->assertEquals($now, $policy->getStatusUpdated(), '', 1);
        $policy->setStatus(Policy::STATUS_UNPAID);
        $this->assertNotEquals($now, $policy->getStatusUpdated(), '', 0);
    }

    public function testCanAdjustPicSureStatusForClaim()
    {
        $policy = new SalvaPhonePolicy();
        $terms = new PolicyTerms();
        $terms->setVersion(PolicyTerms::VERSION_4);
        $policy->setPolicyTerms($terms);

        $this->assertNull($policy->getPicSureStatus());
        $this->assertTrue($policy->canAdjustPicSureStatusForClaim());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED);
        $this->assertTrue($policy->canAdjustPicSureStatusForClaim());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_INVALID);
        $this->assertTrue($policy->canAdjustPicSureStatusForClaim());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_MANUAL);
        $this->assertTrue($policy->canAdjustPicSureStatusForClaim());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $this->assertFalse($policy->canAdjustPicSureStatusForClaim());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $this->assertFalse($policy->canAdjustPicSureStatusForClaim());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED);
        $this->assertFalse($policy->canAdjustPicSureStatusForClaim());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_PREAPPROVED);
        $this->assertFalse($policy->canAdjustPicSureStatusForClaim());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_DISABLED);
        $this->assertFalse($policy->canAdjustPicSureStatusForClaim());
    }

    public function testPicSureStatusWithClaimsPicSureRedo()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testPicSureStatusWithClaimsPicSureRedo', $this));
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->flush();
        $this->assertNotNull($policy->getId());

        $claimA = new Claim();
        $policy->addClaim($claimA);

        $claimA->setStatus(Claim::STATUS_FNOL);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );

        $claimA->setStatus(Claim::STATUS_SUBMITTED);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );

        $claimA->setStatus(Claim::STATUS_INREVIEW);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );

        $claimA->setStatus(Claim::STATUS_DECLINED);
        $this->assertNull($policy->getPicSureStatusWithClaims());

        $claimA->setStatus(Claim::STATUS_WITHDRAWN);
        $this->assertNull($policy->getPicSureStatusWithClaims());

        $claimA->setStatus(Claim::STATUS_APPROVED);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );

        $claimB = new Claim();
        $policy->addClaim($claimB);

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_INVALID);
        $claimA->setIgnoreWarningFlags(Claim::WARNING_FLAG_CLAIMS_ALLOW_PICSURE_REDO);

        $claimB->setStatus(Claim::STATUS_FNOL);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );

        $claimB->setStatus(Claim::STATUS_SUBMITTED);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );

        $claimB->setStatus(Claim::STATUS_INREVIEW);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );

        $claimB->setStatus(Claim::STATUS_DECLINED);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_INVALID,
            $policy->getPicSureStatusWithClaims()
        );

        $claimB->setStatus(Claim::STATUS_WITHDRAWN);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_INVALID,
            $policy->getPicSureStatusWithClaims()
        );

        $claimB->setStatus(Claim::STATUS_APPROVED);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );
    }

    public function testPicSureStatusWithClaims()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testPicSureStatusWithClaims', $this));
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->flush();
        $this->assertNotNull($policy->getId());

        $claimA = new Claim();
        $policy->addClaim($claimA);

        $claimA->setStatus(Claim::STATUS_FNOL);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );

        $claimA->setStatus(Claim::STATUS_SUBMITTED);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );

        $claimA->setStatus(Claim::STATUS_INREVIEW);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );

        $claimA->setStatus(Claim::STATUS_DECLINED);
        $this->assertNull($policy->getPicSureStatusWithClaims());

        $claimA->setStatus(Claim::STATUS_WITHDRAWN);
        $this->assertNull($policy->getPicSureStatusWithClaims());

        $claimA->setStatus(Claim::STATUS_APPROVED);
        $this->assertEquals(
            PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
            $policy->getPicSureStatusWithClaims()
        );

        $claimB = new Claim();
        $policy->addClaim($claimB);

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_INVALID);

        $claimStatus = array(
            Claim::STATUS_FNOL,
            Claim::STATUS_SUBMITTED,
            Claim::STATUS_INREVIEW,
            Claim::STATUS_DECLINED,
            Claim::STATUS_WITHDRAWN,
            Claim::STATUS_APPROVED,
            Claim::STATUS_SETTLED
        );

        foreach ($claimStatus as $status) {
            $claimB->setStatus($status);
            $this->assertEquals(
                PhonePolicy::PICSURE_STATUS_CLAIM_PREVENTED,
                $policy->getPicSureStatusWithClaims()
            );
        }

        $claimC = new Claim();
        $policy->addClaim($claimC);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_MANUAL);
        foreach ($claimStatus as $status) {
            $claimC->setStatus($status);
            $this->assertEquals(
                PhonePolicy::PICSURE_STATUS_MANUAL,
                $policy->getPicSureStatusWithClaims()
            );
        }

        $claimD = new Claim();
        $policy->addClaim($claimD);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_PREAPPROVED);
        foreach ($claimStatus as $status) {
            $claimD->setStatus($status);
            $this->assertEquals(
                PhonePolicy::PICSURE_STATUS_PREAPPROVED,
                $policy->getPicSureStatusWithClaims()
            );
        }

        $claimE = new Claim();
        $policy->addClaim($claimE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        foreach ($claimStatus as $status) {
            $claimE->setStatus($status);
            $this->assertEquals(
                PhonePolicy::PICSURE_STATUS_APPROVED,
                $policy->getPicSureStatusWithClaims()
            );
        }

        $claimF = new Claim();
        $policy->addClaim($claimF);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED);
        foreach ($claimStatus as $status) {
            $claimF->setStatus($status);
            $this->assertEquals(
                PhonePolicy::PICSURE_STATUS_REJECTED,
                $policy->getPicSureStatusWithClaims()
            );
        }

        $claimG = new Claim();
        $policy->addClaim($claimG);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        foreach ($claimStatus as $status) {
            $claimG->setStatus($status);
            $this->assertEquals(
                PhonePolicy::PICSURE_STATUS_APPROVED,
                $policy->getPicSureStatusWithClaims()
            );
        }
    }

    public function testPicSureStatusNotStartedRemovesPicSureFiles()
    {
        $imei = new ImeiFile();
        $imei->setBucket("testbucket");
        $imei->setKey("imei.png");

        $picsure = new PicSureFile();
        $picsure->setBucket("testbucket");
        $picsure->setKey("picsure.png");

        $policy = static::createUserPolicy(true);
        $policy->addPolicyFile($imei);
        $policy->addPolicyFile($picsure);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->flush();

        $files = $policy->getPolicyFiles();
        $this->assertEquals(2, count($files));
        $this->assertEquals("ImeiFile", $files[0]->getFileType());
        $this->assertEquals("PicSureFile", $files[1]->getFileType());

        $policy->setPicSureStatus("");
        static::$dm->persist($policy);
        static::$dm->flush();

        $files = $policy->getPolicyFiles();
        $this->assertEquals(1, count($files));
        $this->assertEquals("ImeiFile", $files[0]->getFileType());
        $this->assertFalse($policy->getPicSureApprovedDate());
    }

    public function testEmptyPolicyReturnsCorrectApiData()
    {
        $policy = new SalvaPhonePolicy();
        $phone = new Phone();
        $phone->init('foo', 'bar', 7.29, self::getLatestPolicyTerms(static::$dm), 1.50);
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
        $policyA = static::createUserPolicy(true, new \DateTime('2016-01-01'));
        $policyA->getUser()->setEmail(static::generateEmail('policya', $this));
        $policyB = static::createUserPolicy(true, new \DateTime('2016-01-01'));
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
        $claimA->setLossDate(new \DateTime("2016-01-01"));
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $claimA->setClosedDate(new \DateTime("2016-01-01"));
        $policyA->addClaim($claimA);
        $this->assertFalse($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-02-01")));
        $this->assertTrue($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-01-15")));

        $claimB = new Claim();
        $claimB->setRecordedDate(new \DateTime("2016-02-01"));
        $claimB->setLossDate(new \DateTime("2016-02-01"));
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

    public function testSelfClaimed()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->getUser()->setEmail(static::generateEmail('testSelfClaimed', $this));
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyA);
        static::$dm->flush();
        $this->assertNotNull($policyA->getId());

        $claimA = new Claim();
        $claimA->setRecordedDate(new \DateTime("2016-01-01"));
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $claimA->setClosedDate(new \DateTime("2016-01-01"));
        $policyA->addClaim($claimA);
        $this->assertTrue($policyA->hasMonetaryClaimed());
        $this->assertEquals(
            SalvaPhonePolicy::RISK_CONNECTED_SELF_CLAIM,
            $policyA->getRiskReason(new \DateTime("2016-01-10"))
        );
    }

    public function testRenewedNoPreviousClaim()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testRenewedNoPreviousClaim', $this));
        $policyRenewed = static::createUserPolicy(true);
        $policyRenewed->setUser($policy->getUser());
        $policy->link($policyRenewed);
        static::$dm->persist($policyRenewed->getUser());
        static::$dm->persist($policy);
        static::$dm->persist($policyRenewed);
        static::$dm->flush();
        $this->assertNotNull($policyRenewed->getId());

        $claimA = new Claim();
        $claimA->setRecordedDate(new \DateTime("2016-01-01"));
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $claimA->setClosedDate(new \DateTime("2016-01-01"));
        $policyRenewed->addClaim($claimA);
        $this->assertTrue($policyRenewed->hasMonetaryClaimed());
        $this->assertEquals(
            SalvaPhonePolicy::RISK_RENEWED_NO_PREVIOUS_CLAIM,
            $policyRenewed->getRiskReason(new \DateTime("2016-01-10"))
        );
    }

    public function testLinkedClaimed()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->getUser()->setEmail(static::generateEmail('testLinkedClaimed', $this));
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyA);
        static::$dm->flush();
        $this->assertNotNull($policyA->getId());
        $this->assertFalse($policyA->hasMonetaryClaimed());

        $claimA = new Claim();
        $claimA->setRecordedDate(new \DateTime("2016-01-01"));
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $claimA->setClosedDate(new \DateTime("2016-01-01"));
        $policyA->addLinkedClaim($claimA);
        $this->assertFalse($policyA->hasMonetaryClaimed());
        $this->assertTrue($policyA->hasMonetaryClaimed(false, true));
        $this->assertEquals(
            SalvaPhonePolicy::RISK_CONNECTED_SELF_CLAIM,
            $policyA->getRiskReason(new \DateTime("2016-01-10"))
        );
    }

    public function testHasNetworkClaimedInLast30DaysWithOpenStatus()
    {
        $policyA = static::createUserPolicy(true, new \DateTime('2016-01-01'));
        $policyB = static::createUserPolicy(true, new \DateTime('2016-01-01'));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $this->assertFalse($policyA->hasNetworkClaimedInLast30Days(null, true));

        $claimA = new Claim();
        $claimA->setRecordedDate(new \DateTime("2016-01-01"));
        $claimA->setLossDate(new \DateTime("2016-01-01"));
        $claimA->setStatus(Claim::STATUS_APPROVED);
        $policyA->addClaim($claimA);
        $this->assertFalse($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-02-01")));
        $this->assertFalse($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-02-01"), true));

        $this->assertFalse($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-01-15")));
        $this->assertTrue($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-01-15"), true));

        $claimB = new Claim();
        $claimB->setRecordedDate(new \DateTime("2016-02-01"));
        $claimB->setLossDate(new \DateTime("2016-02-01"));
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policyA->addClaim($claimB);
        $this->assertFalse($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-02-21")));
        $this->assertTrue($policyB->hasNetworkClaimedInLast30Days(new \DateTime("2016-02-21"), true));
    }

    public function testCalculatePotValue()
    {
        $policyConnected = static::createUserPolicy(true, null, true);
        $policyConnected->setStart(new \DateTime("2017-01-01"));

        for ($i = 1; $i <= $policyConnected->getMaxConnections(new \DateTime("2017-01-01")); $i++) {
            $policy = static::createUserPolicy(true);
            $policy->setStart(new \DateTime("2017-01-01"));
            list($connectionA, $connectionB) = $this->createLinkedConnections($policyConnected, $policy, 10, 10);
        }
        $this->assertEquals($policyConnected->getMaxPot(), $policyConnected->calculatePotValue(false));

        $policy = static::createUserPolicy(true);
        $policy->setStart(new \DateTime("2017-01-01"));
        list($connectionA, $connectionB) = $this->createLinkedConnections(
            $policyConnected,
            $policy,
            10,
            10,
            null,
            null,
            false
        );
        $this->assertEquals($policyConnected->getMaxPot(), $policyConnected->calculatePotValue(false));
    }

    public function testCalculatePotValueReverse()
    {
        $policyConnected = static::createUserPolicy(true, null, true);
        $policyConnected->setStart(new \DateTime("2017-01-01"));

        for ($i = 1; $i <= $policyConnected->getMaxConnections(new \DateTime("2017-01-01")); $i++) {
            $policy = static::createUserPolicy(true);
            $policy->setStart(new \DateTime("2017-01-01"));
            list($connectionA, $connectionB) = $this->createLinkedConnections(
                $policyConnected,
                $policy,
                10,
                10,
                null,
                null,
                false
            );
        }
        //\Doctrine\Common\Util\Debug::dump($policyConnected);
        $this->assertEquals(0, $policyConnected->calculatePotValue(false));

        $policy = static::createUserPolicy(true);
        $policy->setStart(new \DateTime("2017-01-01"));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyConnected, $policy, 10, 10);

        $this->assertEquals(10, $policyConnected->calculatePotValue(false));
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

    /**
     * @expectedException \Exception
     */
    public function testSetPhoneDisallowedExcess()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('testGetRiskPolicyPendingCancellation', $this));
        self::$dm->persist($user);
        self::addAddress($user);

        $phone = new Phone();
        $oldTerms = new PolicyTerms();
        $oldTerms->setVersion(PolicyTerms::VERSION_1);
        $phone->init('foo', 'bar', 5, $oldTerms);

        $policy = new SalvaPhonePolicy();
        $policy->init($user, self::getPolicyTermsVersion(static::$dm, 'Version 12 February 2019'));
        $policy->setPhone($phone);
    }

    public function testGetCurrentExcessAndPicSureStatus()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_WARRANTY);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $policy->addClaim($claim);

        $this->assertCount(1, $policy->getClaims());
        $this->assertEquals(150, $policy->getCurrentExcess()->getTheft());
        $this->assertEquals(150, $policy->getClaims()[0]->getExpectedExcess()->getTheft());
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $this->assertEquals(70, $policy->getCurrentExcess()->getTheft());
        $this->assertEquals(70, $policy->getClaims()[0]->getExpectedExcess()->getTheft());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED);
        $this->assertEquals(150, $policy->getCurrentExcess()->getTheft());
        $this->assertEquals(150, $policy->getClaims()[0]->getExpectedExcess()->getTheft());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_MANUAL);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $this->assertEquals(150, $policy->getClaims()[0]->getExpectedExcess()->getTheft());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $this->assertEquals(150, $policy->getClaims()[0]->getExpectedExcess()->getTheft());
    }

    /**
     * @expectedException \Exception
     */
    public function testInitWithPremiumNonPicSure()
    {
        $terms = new PolicyTerms();
        $terms->setVersion(PolicyTerms::VERSION_1);

        $premium = new PhonePremium();
        $premium->setExcess(PolicyTerms::getHighExcess());

        $user = new User();
        $user->setEmail(static::generateEmail('testInitWithPremiumNonPicSure', $this));
        $policy = new PhonePolicy();
        $policy->setPremium($premium);
        $policy->init($user, $terms);
        $this->assertTrue(true);
    }

    public function testInitWithPremiumAllowed()
    {
        $terms = new PolicyTerms();
        $terms->setVersion(PolicyTerms::VERSION_10);

        $premium = new PhonePremium();
        $premium->setExcess(PolicyTerms::getHighExcess());
        $premium->setPicSureExcess(PolicyTerms::getLowExcess());

        $user = new User();
        $user->setEmail(static::generateEmail('testInitWithPremiumAllowed', $this));
        $policy = new PhonePolicy();
        $policy->setPremium($premium);
        $policy->init($user, $terms);
        $this->assertTrue(true);
    }

    /**
     * @expectedException \Exception
     */
    public function testInitWithPremiumException()
    {
        $terms = new PolicyTerms();
        $terms->setVersion(PolicyTerms::VERSION_10);

        $premium = new PhonePremium();
        $premium->setExcess(PolicyTerms::getLowExcess());
        $premium->setPicSureExcess(PolicyTerms::getLowExcess());

        $user = new User();
        $user->setEmail(static::generateEmail('testInitWithPremiumException', $this));
        $policy = new PhonePolicy();
        $policy->setPremium($premium);
        $policy->init($user, $terms);
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
        $policyConnected = static::createUserPolicy(true, null, true);
        $policyConnected->setStart(new \DateTime("2016-01-01"));

        $policyClaim = static::createUserPolicy(true, null, true);
        $policyClaim->setStart(new \DateTime("2016-01-01"));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyConnected, $policyClaim, 10, 10);
        $policyClaim->updatePotValue();
        $policyConnected->updatePotValue();

        $this->assertEquals(SalvaPhonePolicy::RISK_LEVEL_LOW, $policyConnected->getRisk());
    }

    public function testGetRiskPolicyConnectionsClaimedPre30()
    {
        $policyConnected = static::createUserPolicy(true, null, true);
        $policyConnected->setStart(new \DateTime("2016-01-01"));

        $policyClaim = static::createUserPolicy(true, null, true);
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
        $claim->setLossDate(new \DateTime("2016-01-10"));
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policyClaim->addClaim($claim);

        $this->assertEquals(
            SalvaPhonePolicy::RISK_LEVEL_MEDIUM,
            $policyConnected->getRisk(new \DateTime("2016-01-20"))
        );
    }

    public function testGetRiskPolicyConnectionsClaimedPost30()
    {
        $policyConnected = static::createUserPolicy(true, null, true);
        $policyConnected->setStart(new \DateTime("2016-01-01"));

        $policyClaim = static::createUserPolicy(true, null, true);
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

    public function testPotValueWithClaimedCancelledPolicy()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->getUser()->setEmail(static::generateEmail('testNetworkPotValueWithClaimedCancelledPolicy-a', $this));
        $policyB = static::createUserPolicy(true);
        $policyB->getUser()->setEmail(static::generateEmail('testNetworkPotValueWithClaimedCancelledPolicy-b', $this));
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

        $policyA->updatePotValue();
        $policyB->updatePotValue();

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $claimA = new Claim();
        $claimA->setLossDate(\DateTime::createFromFormat('U', time()));
        //$claimA->setRecordedDate(new \DateTime("2016-01-01"));
        $claimA->setStatus(Claim::STATUS_SETTLED);
        //$claimA->setClosedDate(new \DateTime("2016-01-01"));
        $policyA->addClaim($claimA);

        $policyA->updatePotValue();
        $policyB->updatePotValue();

        $this->assertEquals(0, $policyA->getPotValue());
        $this->assertEquals(0, $policyB->getPotValue());

        $policyA->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);

        $this->assertEquals(SalvaPhonePolicy::STATUS_CANCELLED, $policyA->getStatus());
        $this->assertEquals(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, $policyA->getCancelledReason());

        $policyA->updatePotValue();
        $policyB->updatePotValue();

        $this->assertEquals(0, $policyA->getPotValue());
        $this->assertEquals(0, $policyB->getPotValue());
    }

    public function testGetRiskReasonPolicyRewardConnection()
    {
        $reward = $this->createReward(static::generateEmail('testGetRiskPolicyRewardConnection', $this));

        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testGetRiskPolicyRewardConnection-user', $this));
        $policy->setStart(\DateTime::createFromFormat('U', time()));
        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());

        $connection = static::$invitationService->addReward($policy, $reward, 10);
        $this->assertEquals(10, $policy->getPotValue());
        $this->assertEquals(10, $connection->getPromoValue());

        $this->assertEquals(SalvaPhonePolicy::RISK_NOT_CONNECTED_NEW_POLICY, $policy->getRiskReason());
    }

    public function testGetRiskReasonPolicyRenewed()
    {
        $now = \DateTime::createFromFormat('U', time());
        $yearAgo = clone $now;
        $yearAgo = $yearAgo->sub(new \DateInterval('P1Y'));
        $policyPrev = static::createUserPolicy(true);
        $policyPrev->setStart($yearAgo);
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testGetRiskReasonPolicyRenewed', $this));
        $policy->setStart(\DateTime::createFromFormat('U', time()));
        $policyPrev->link($policy);
        static::$dm->persist($policyPrev);
        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());

        $this->assertEquals(SalvaPhonePolicy::RISK_NOT_CONNECTED_ESTABLISHED_POLICY, $policyPrev->getRiskReason());
    }

    public function testGetRiskReasonPolicyPromoOnlyConnection()
    {
        $policyConnected = static::createUserPolicy(true, null, true);
        $policyConnected->setStart(new \DateTime("2016-01-01"));

        $policyClaim = static::createUserPolicy(true, null, true);
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

    public function testGetRiskReasonOnlySelfConnectedNotConnected()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testGetRiskReasonOnlySelfConnectedNotConnected', $this),
            'bar'
        );
        $policy1 = static::initPolicy($user, static::$dm, static::$phone, null, true);
        $policy2 = static::initPolicy($user, static::$dm, static::$phone, null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy1);
        static::$policyService->create($policy2);
        static::$policyService->setEnvironment('test');
        // Policy needs to be active
        $policy1->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertEquals(0, count($policy1->getStandardConnections()));
        $this->assertEquals(0, count($policy2->getStandardConnections()));
        $this->assertEquals(0, count($policy1->getStandardSelfConnections()));
        $this->assertEquals(0, count($policy2->getStandardSelfConnections()));

        static::$invitationService->setEnvironment('prod');
        self::$invitationService->connect($policy1, $policy2);
        $this->assertEquals(1, count($policy1->getStandardConnections()));
        $this->assertEquals(1, count($policy2->getStandardConnections()));
        $this->assertEquals(1, count($policy1->getStandardSelfConnections()));
        $this->assertEquals(1, count($policy2->getStandardSelfConnections()));
        static::$invitationService->setEnvironment('test');

        $this->assertEquals(SalvaPhonePolicy::RISK_NOT_CONNECTED_NEW_POLICY, $policy1->getRiskReason());
        $this->assertEquals(SalvaPhonePolicy::RISK_NOT_CONNECTED_NEW_POLICY, $policy2->getRiskReason());
    }

    public function testUseForAttribution()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testUseForAttribution', $this),
            'bar'
        );
        $policy1 = static::initPolicy($user, static::$dm, static::$phone, null, true);
        $policy2 = static::initPolicy($user, static::$dm, static::$phone, null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy1, new \DateTime('2018-01-02'));
        static::$policyService->create($policy2, new \DateTime('2018-01-01'));
        static::$policyService->setEnvironment('test');
        // Policy needs to be active
        $policy1->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertTrue($policy2->useForAttribution());
        $this->assertFalse($policy1->useForAttribution());
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

    public function testCalculatePotValueOneConnection()
    {
        $policyA = static::createUserPolicy(true);
        $policyB = static::createUserPolicy(true);
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $this->assertEquals(10, $policyA->calculatePotValue());
    }

    public function testCalculatePromoPotValueOneConnection()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->setPromoCode(SalvaPhonePolicy::PROMO_LAUNCH);
        $policyA->setPhone(static::$phone);
        $policyB = static::createUserPolicy(true);
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
        $policyA = static::createUserPolicy(true);
        $policyB = static::createUserPolicy(true);
        list($connectionInitialA, $connectionInitialB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);
        list($connectionPostCliffA, $connectionPostCliffB) = $this->createLinkedConnections($policyA, $policyB, 2, 2);

        $this->assertEquals(12, $policyA->calculatePotValue());
    }

    public function testCalculatePotValueOneValidNetworkClaimThirtyPot()
    {
        $policy = static::createUserPolicy(true);

        $linkedPolicies = [];
        for ($i = 1; $i <= 3; $i++) {
            $linkedPolicy = static::createUserPolicy(true);
            list($connectionA, $connectionB) = $this->createLinkedConnections($policy, $linkedPolicy, 10, 10);
            $linkedPolicies[] = $linkedPolicy;
        }
        $this->assertEquals(30, $policy->calculatePotValue());

        $claim = new Claim();
        $claim->setLossDate(\DateTime::createFromFormat('U', time()));
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
        $claim->setLossDate(\DateTime::createFromFormat('U', time()));
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $linkedPolicies[0]->addClaim($claim);
        $this->assertEquals(10, $policy->calculatePotValue());
    }

    public function testCalculatePotValueOneValidClaimFourtyPot()
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
        $policy->addClaim($claim);
        $this->assertEquals(0, $policy->calculatePotValue());
    }

    public function testCalculatePotValueTwoValidNetworkClaimFourtyPot()
    {
        $policy = static::createUserPolicy(true);

        $linkedPolicies = [];
        for ($i = 1; $i <= 4; $i++) {
            $linkedPolicy = static::createUserPolicy(true);
            list($connectionA, $connectionB) = $this->createLinkedConnections($policy, $linkedPolicy, 10, 10);
            $linkedPolicies[] = $linkedPolicy;
        }
        $this->assertEquals(40, $policy->calculatePotValue());

        $claimA = new Claim();
        $claimA->setLossDate(\DateTime::createFromFormat('U', time()));
        $claimA->setType(Claim::TYPE_LOSS);
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $linkedPolicies[0]->addClaim($claimA);

        $claimB = new Claim();
        $claimB->setLossDate(\DateTime::createFromFormat('U', time()));
        $claimB->setType(Claim::TYPE_LOSS);
        $claimB->setStatus(Claim::STATUS_SETTLED);
        $linkedPolicies[1]->addClaim($claimB);

        $this->assertEquals(0, $policy->calculatePotValue());
    }

    public function testCalculatePotValueOneInvalidNetworkClaimFourtyPot()
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
        $claimA->setLossDate(new \DateTime('2016-02-29'));
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

        // test is if the above generates an exception
        $this->assertTrue(true);
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
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        $policyC->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();
        list($connectionAB, $connectionBA) = $this->createLinkedConnections($policyA, $policyB, 10, 10);
        list($connectionAC, $connectionCA) = $this->createLinkedConnections($policyA, $policyC, 10, 10);
        list($connectionBC, $connectionCB) = $this->createLinkedConnections($policyB, $policyC, 10, 10);
        $policyA->updatePotValue();
        $policyB->updatePotValue();
        $policyC->updatePotValue();
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyA->getStatus());
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyB->getStatus());
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyC->getStatus());

        $this->assertEquals(20, $policyA->getPotValue());
        $this->assertEquals(20, $policyB->getPotValue());
        $this->assertEquals(20, $policyC->getPotValue());

        $policyA->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);

        $this->assertEquals(SalvaPhonePolicy::STATUS_CANCELLED, $policyA->getStatus());
        $this->assertEquals(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, $policyA->getCancelledReason());
        $now = \DateTime::createFromFormat('U', time());
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
            /** @var Connection $networkConnection */
            if ($networkConnection->getLinkedPolicy()->getId() == $policyA->getId()) {
                $this->assertEquals(0, $networkConnection->getTotalValue());
            } else {
                $this->assertGreaterThan(0, $networkConnection->getTotalValue());
            }
        }
    }

    public function testCancelPolicyUserDeclined()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->getUser()->setEmail(static::generateEmail('testCancelPolicyUserDeclinedA', $this));
        $policyB = static::createUserPolicy(true);
        $policyB->getUser()->setEmail(static::generateEmail('testCancelPolicyUserDeclinedB', $this));
        $policyC = static::createUserPolicy(true);
        $policyC->getUser()->setEmail(static::generateEmail('testCancelPolicyUserDeclinedC', $this));
        $policyD = static::createUserPolicy(true);
        $policyD->getUser()->setEmail(static::generateEmail('testCancelPolicyUserDeclinedD', $this));
        $policyE = static::createUserPolicy(true);
        $policyE->getUser()->setEmail(static::generateEmail('testCancelPolicyUserDeclinedE', $this));
        $policyF = static::createUserPolicy(true);
        $policyF->getUser()->setEmail(static::generateEmail('testCancelPolicyUserDeclinedF', $this));
        static::$dm->persist($policyA);
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB);
        static::$dm->persist($policyB->getUser());
        static::$dm->persist($policyC);
        static::$dm->persist($policyC->getUser());
        static::$dm->persist($policyD);
        static::$dm->persist($policyD->getUser());
        static::$dm->persist($policyE);
        static::$dm->persist($policyE->getUser());
        static::$dm->persist($policyF);
        static::$dm->persist($policyF->getUser());
        static::$dm->flush();

        $this->assertFalse($policyA->isCancelledWithUserDeclined());
        $policyA->cancel(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD);
        $policyB->cancel(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD);
        $policyC->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policyC->setCancelledReason(SalvaPhonePolicy::CANCELLED_BADRISK);
        $policyD->cancel(SalvaPhonePolicy::CANCELLED_UPGRADE);

        $this->assertTrue($policyA->isCancelledWithUserDeclined());
        $this->assertTrue($policyB->isCancelledWithUserDeclined());
        $this->assertTrue($policyC->isCancelledWithUserDeclined());
        $this->assertFalse($policyD->isCancelledWithUserDeclined());

        $this->assertFalse($policyE->isCancelledWithUserDeclined());
        $policyE->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);
        $claimE = new Claim();
        $claimE->setStatus(Claim::STATUS_APPROVED);
        $policyE->addClaim($claimE);
        $this->assertTrue($policyE->isCancelledWithUserDeclined());

        $claimE->setIgnoreWarningFlags(Claim::WARNING_FLAG_IGNORE_USER_DECLINED);
        $this->assertFalse($policyE->isCancelledWithUserDeclined());

        $this->assertFalse($policyF->isCancelledWithUserDeclined());
        $claimF = new Claim();
        $claimF->setStatus(Claim::STATUS_WITHDRAWN);
        $policyF->addClaim($claimF);
        $policyF->setStatus(SalvaPhonePolicy::STATUS_UNPAID);
        $policyF->cancel(SalvaPhonePolicy::CANCELLED_UNPAID);
        $this->assertTrue($policyF->isCancelledWithUserDeclined());

        $claimF->setIgnoreWarningFlags(Claim::WARNING_FLAG_IGNORE_USER_DECLINED);
        $this->assertFalse($policyF->isCancelledWithUserDeclined());
    }

    public function testCancelPolicyPolicyDeclined()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->getUser()->setEmail(static::generateEmail('testCancelPolicyPolicyDeclinedA', $this));
        $policyB = static::createUserPolicy(true);
        $policyB->getUser()->setEmail(static::generateEmail('testCancelPolicyPolicyDeclinedB', $this));
        static::$dm->persist($policyA);
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB);
        static::$dm->persist($policyB->getUser());
        static::$dm->flush();

        $this->assertFalse($policyA->isCancelledWithPolicyDeclined());
        $policyA->cancel(SalvaPhonePolicy::CANCELLED_DISPOSSESSION);
        $policyB->cancel(SalvaPhonePolicy::CANCELLED_WRECKAGE);

        $this->assertTrue($policyA->isCancelledWithPolicyDeclined());
        $this->assertTrue($policyB->isCancelledWithPolicyDeclined());
    }

    /**
     * @expectedException \Exception
     */
    public function testCancelPolicyCooloffFullRefund()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testCancelPolicyCooloffFullRefund', $this));
        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());
        static::$dm->flush();

        $policy->cancel(SalvaPhonePolicy::CANCELLED_COOLOFF, null, true);
    }

    public function testCancelPolicyFullRefund()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testCancelPolicyFullRefund', $this));
        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());
        static::$dm->flush();

        $this->assertNull($policy->isCancelledFullRefund());
        $policy->cancel(SalvaPhonePolicy::CANCELLED_UPGRADE, null, true);
        $this->assertTrue($policy->isCancelledFullRefund());
    }

    public function testIsFullyPaid()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->getUser()->setEmail(static::generateEmail('testIsFullyPaid', $this));
        static::$dm->persist($policyA);
        static::$dm->persist($policyA->getUser());
        static::$dm->flush();

        $this->assertFalse($policyA->isFullyPaid());
        $bacs = new BacsPayment();
        $bacs->setManual(true);
        $bacs->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacs->setSuccess(true);
        $bacs->setAmount($policyA->getPremium()->getMonthlyPremiumPrice());
        $policyA->addPayment($bacs);
        $this->assertFalse($policyA->isFullyPaid());

        $bacs = new BacsPayment();
        $bacs->setManual(true);
        $bacs->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacs->setSuccess(true);
        $bacs->setAmount($policyA->getPremium()->getMonthlyPremiumPrice() * 11);
        $policyA->addPayment($bacs);
        $this->assertTrue($policyA->isFullyPaid());

        $bacs = new BacsPayment();
        $bacs->setManual(true);
        $bacs->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacs->setSuccess(true);
        $bacs->setAmount($policyA->getPremium()->getMonthlyPremiumPrice());
        $policyA->addPayment($bacs);
        $this->assertTrue($policyA->isFullyPaid());
    }

    public function testIsCancelledAndPaymentOwed()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->getUser()->setEmail(static::generateEmail('testIsCancelledAndPaymentOwedA', $this));
        $policyB = static::createUserPolicy(true);
        $policyB->getUser()->setEmail(static::generateEmail('testIsCancelledAndPaymentOwedB', $this));
        $policyC = static::createUserPolicy(true);
        $policyC->getUser()->setEmail(static::generateEmail('testIsCancelledAndPaymentOwedC', $this));
        $claimB = new Claim();
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policyB->addClaim($claimB);
        $claimC = new Claim();
        $claimC->setStatus(Claim::STATUS_APPROVED);
        $policyC->addClaim($claimC);
        static::$dm->persist($policyA);
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB);
        static::$dm->persist($policyB->getUser());
        static::$dm->persist($policyC);
        static::$dm->persist($policyC->getUser());
        static::$dm->flush();

        $this->assertFalse($policyA->isCancelledAndPaymentOwed());
        $this->assertFalse($policyB->isCancelledAndPaymentOwed());
        $this->assertFalse($policyC->isCancelledAndPaymentOwed());

        $bacsA = new BacsPayment();
        $bacsA->setManual(true);
        $bacsA->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsA->setSuccess(true);
        $bacsA->setAmount($policyA->getPremium()->getMonthlyPremiumPrice());
        $policyA->addPayment($bacsA);
        $bacsB = new BacsPayment();
        $bacsB->setManual(true);
        $bacsB->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsB->setSuccess(true);
        $bacsB->setAmount($policyB->getPremium()->getMonthlyPremiumPrice());
        $policyB->addPayment($bacsB);
        $bacsC = new BacsPayment();
        $bacsC->setManual(true);
        $bacsC->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsC->setSuccess(true);
        $bacsC->setAmount($policyC->getPremium()->getMonthlyPremiumPrice());
        $policyC->addPayment($bacsC);

        $this->assertFalse($policyA->isFullyPaid());
        $this->assertFalse($policyB->isFullyPaid());
        $this->assertFalse($policyC->isFullyPaid());

        $this->assertFalse($policyA->isCancelledAndPaymentOwed());
        $this->assertFalse($policyB->isCancelledAndPaymentOwed());
        $this->assertFalse($policyC->isCancelledAndPaymentOwed());

        $policyB->setStatus(Policy::STATUS_CANCELLED);
        $policyB->setCancelledReason(Policy::CANCELLED_UNPAID);
        $policyC->setStatus(Policy::STATUS_CANCELLED);
        $policyC->setCancelledReason(Policy::CANCELLED_UPGRADE);

        $this->assertFalse($policyA->isCancelledAndPaymentOwed());
        $this->assertTrue($policyB->isCancelledAndPaymentOwed());
        $this->assertFalse($policyC->isCancelledAndPaymentOwed());

        $bacsA = new BacsPayment();
        $bacsA->setManual(true);
        $bacsA->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsA->setSuccess(true);
        $bacsA->setAmount($policyA->getPremium()->getMonthlyPremiumPrice() * 11);
        $policyA->addPayment($bacsA);
        $bacsB = new BacsPayment();
        $bacsB->setManual(true);
        $bacsB->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsB->setSuccess(true);
        $bacsB->setAmount($policyB->getPremium()->getMonthlyPremiumPrice() * 11);
        $policyB->addPayment($bacsB);
        $bacsC = new BacsPayment();
        $bacsC->setManual(true);
        $bacsC->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsC->setSuccess(true);
        $bacsC->setAmount($policyC->getPremium()->getMonthlyPremiumPrice() * 11);
        $policyC->addPayment($bacsC);

        $this->assertTrue($policyA->isFullyPaid());
        $this->assertTrue($policyB->isFullyPaid());
        $this->assertTrue($policyC->isFullyPaid());

        $this->assertFalse($policyA->isCancelledAndPaymentOwed());
        $this->assertFalse($policyB->isCancelledAndPaymentOwed());
        $this->assertFalse($policyC->isCancelledAndPaymentOwed());
    }

    public function testCancelPolicyUpgrade()
    {
        $policyA = static::createUserPolicy(true);
        $policyA->getUser()->setEmail(static::generateEmail('testCancelPolicyUpgrade', $this));
        static::$dm->persist($policyA);
        static::$dm->persist($policyA->getUser());
        static::$dm->flush();

        $policyA->cancel(SalvaPhonePolicy::CANCELLED_UPGRADE);

        $this->assertEquals(SalvaPhonePolicy::STATUS_CANCELLED, $policyA->getStatus());
        $this->assertEquals(SalvaPhonePolicy::CANCELLED_UPGRADE, $policyA->getCancelledReason());
        $this->assertFalse($policyA->getUser()->isLocked());
    }

    public function testCancelPolicyCancelsScheduledPayments()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(self::generateEmail('testCancelPolicyCancelsScheduledPayments', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, new \DateTime("2016-01-01"), rand(1, 9999));
        $policy->setStatus(Policy::STATUS_ACTIVE);

        self::addPayment(
            $policy,
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION
        );

        $date = new \DateTime("2016-01-01");
        for ($i = 0; $i < 11; $i++) {
            $date = $date->add(new \DateInterval('P1M'));
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setAmount(1);
            $scheduledPayment->setScheduled(clone $date);
            $policy->addScheduledPayment($scheduledPayment);
        }
        static::$dm->persist($policy);
        static::$dm->persist($user);
        static::$dm->flush();

        $this->assertEquals(11, $policy->getOutstandingScheduledPaymentsAmount());

        $policy->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);
        foreach ($policy->getScheduledPayments() as $scheduledPayment) {
            $this->assertEquals(ScheduledPayment::STATUS_CANCELLED, $scheduledPayment->getStatus());
        }

        $this->assertEquals(0, $policy->getOutstandingScheduledPaymentsAmount());
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

        $payment = self::addPayment(
            $policy,
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION
        );

        static::$dm->persist($policy);
        static::$dm->persist($user);
        static::$dm->flush();

        $this->assertTrue($policy->validateRefundAmountIsInstallmentPrice($payment));
        $payment->setAmount($payment->getAmount() + 0.0001);
        $this->assertTrue($policy->validateRefundAmountIsInstallmentPrice($payment));
    }

    public function testValidateRefundAmountIsInstallmentPriceWithDiscount()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(self::generateEmail('testValidateRefundAmountIsInstallmentPriceWithDiscount', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        // not using policy service here, so simulate what's done there
        $policy->setPremiumInstallments(12);
        $policy->getPremium()->setAnnualDiscount(12);
        $payment = new PolicyDiscountPayment();
        $payment->setAmount(12);
        $policy->addPayment($payment);

        static::$dm->persist($policy);
        static::$dm->persist($user);
        static::$dm->flush();

        $payment = self::addPayment(
            $policy,
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice() - 1,
            Salva::MONTHLY_TOTAL_COMMISSION
        );

        static::$dm->flush();

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
            $scheduledPayment->setPolicy($policy);
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

        $policy->incrementSalvaPolicyNumber(\DateTime::createFromFormat('U', time()));
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
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, new \DateTime("2016-01-01"))
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

        $this->assertNull($policy->getLastSuccessfulUserPaymentCredit());

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-01-01');
        $this->assertNotNull($policy->getLastSuccessfulUserPaymentCredit());
        if ($policy->getLastSuccessfulUserPaymentCredit()) {
            $this->assertEquals($date, $policy->getLastSuccessfulUserPaymentCredit()->getDate());
        }

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $payment->setDate(new \DateTime('2016-02-01'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-01-01');
        $this->assertNotNull($policy->getLastSuccessfulUserPaymentCredit());
        if ($policy->getLastSuccessfulUserPaymentCredit()) {
            $this->assertEquals($date, $policy->getLastSuccessfulUserPaymentCredit()->getDate());
        }

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-02-15'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-02-15');
        $this->assertNotNull($policy->getLastSuccessfulUserPaymentCredit());
        if ($policy->getLastSuccessfulUserPaymentCredit()) {
            $this->assertEquals($date, $policy->getLastSuccessfulUserPaymentCredit()->getDate());
        }

        // Neg payment (debit/refund) should be ignored
        $payment = new JudoPayment();
        $payment->setAmount(0 - static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-03-15'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $date = new \DateTime('2016-02-15');
        $this->assertNotNull($policy->getLastSuccessfulUserPaymentCredit());
        if ($policy->getLastSuccessfulUserPaymentCredit()) {
            $this->assertEquals($date, $policy->getLastSuccessfulUserPaymentCredit()->getDate());
        }
    }

    /**
     * @expectedException \Exception
     */
    public function testShouldCancelPolicyMissingPayment()
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
        $policy->setStatus(Policy::STATUS_ACTIVE);

        // Policy doesn't have a payment, so should be expired
        $this->assertTrue($policy->shouldCancelPolicy(null, new \DateTime("2016-01-01")));
    }

    public function testShouldCancelRenewalPolicyMissingPayment()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('expire-policy-missing-payment', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, new \DateTime("2016-01-01"), rand(1, 9999));
        $policy->setPremiumInstallments(12);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $renewal = new SalvaPhonePolicy();
        $renewal->setPhone(static::$phone);
        $renewal->init($user, static::getLatestPolicyTerms(self::$dm));
        $renewal->create(rand(1, 999999), null, new \DateTime("2017-01-01"), rand(1, 9999));
        $renewal->setPremiumInstallments(12);
        $renewal->setStatus(Policy::STATUS_ACTIVE);

        $policy->link($renewal);

        $this->assertEquals(
            new \DateTime('2017-01-01 00:00'),
            $renewal->getNextBillingDate(new \DateTime('2017-01-01'))
        );
        $this->assertEquals(
            new \DateTime('2017-02-01 00:00'),
            $renewal->getNextBillingDate(new \DateTime('2017-01-02'))
        );

        $this->assertEquals(
            new \DateTime('2017-01-31'),
            $renewal->getPolicyExpirationDate(new \DateTime('2017-01-01'))
        );
        $this->assertEquals(
            new \DateTime('2017-01-31'),
            $renewal->getPolicyExpirationDate(new \DateTime('2017-01-02'))
        );

        // Renewal doesn't have a payment, so should be expired after 30 days
        $this->assertFalse($renewal->shouldCancelPolicy(null, new \DateTime("2017-01-01")));
        $this->assertFalse($renewal->shouldCancelPolicy(null, new \DateTime("2017-01-30 23:59:00")));
        $this->assertTrue($renewal->shouldCancelPolicy(null, new \DateTime("2017-01-31 00:01:00")));
        $this->assertTrue($renewal->shouldCancelPolicy(null, new \DateTime("2017-03-01")));
    }

    public function testShouldCancelPolicy()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('expire-policy', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, new \DateTime("2016-01-01"), rand(1, 9999));
        $policy->setPremiumInstallments(12);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-01-01'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $this->assertFalse($policy->shouldCancelPolicy(null, new \DateTime("2016-01-01")));
        $this->assertTrue($policy->shouldCancelPolicy(null, new \DateTime("2016-03-03")));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $payment->setDate(new \DateTime('2016-02-01'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $this->assertFalse($policy->shouldCancelPolicy(null, new \DateTime("2016-01-01")));
        $this->assertTrue($policy->shouldCancelPolicy(null, new \DateTime("2016-03-03")));

        $payment = new JudoPayment();
        $payment->setAmount(static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setDate(new \DateTime('2016-02-08'));
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);

        $this->assertFalse($policy->shouldCancelPolicy(null, new \DateTime("2016-02-09")));
        $this->assertTrue($policy->shouldCancelPolicy(null, new \DateTime("2016-04-15")));
    }

    public function testCanCancelPolicy()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        /* Policy is in progress, shouldn't cancel */
        $this->assertFalse($policy->canCancel(null));

        $user = new User();
        $user->setEmail(static::generateEmail('can-cancel-policy', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $this->assertTrue($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-01")));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-31")));
        $this->assertTrue($policy->canCancel(Policy::CANCELLED_USER_REQUESTED, new \DateTime("2016-01-15")));
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_BADRISK));

        $policy->setStatus(Policy::STATUS_ACTIVE);
        $this->assertTrue($policy->canCancel(null));

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
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-31")));
        $policy->cancel(Policy::CANCELLED_COOLOFF, new \DateTime("2016-01-31"));
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

    public function testCanCancelPolicyExpiredClaimable()
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
        $policy->setStatus(Policy::STATUS_EXPIRED_CLAIMABLE);
        $this->assertFalse($policy->canCancel(Policy::CANCELLED_USER_REQUESTED, new \DateTime("2016-01-01")));
    }

    public function testCanCancelPolicyExpiredWaitClaim()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPhone(static::$phone);

        $user = new User();
        $user->setEmail(static::generateEmail('testCanCancelPolicyExpiredWaitClaim', $this));
        self::addAddress($user);
        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setStart(new \DateTime("2016-01-01"));
        $policy->setEnd(new \DateTime("2016-12-31 23:59"));
        $policy->setStatus(Policy::STATUS_EXPIRED_WAIT_CLAIM);
        $this->assertFalse($policy->canCancel(Policy::STATUS_EXPIRED_WAIT_CLAIM, new \DateTime("2016-01-01")));
    }

    public function testCanCancelPolicyNullStatus()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCanCancelPolicyNullStatus', $this),
            'bar',
            null,
            static::$dm
        );

        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            null,
            false,
            false
        );

        $this->assertFalse($policy->canCancel(null));
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
        $this->assertFalse($policy->isWithinCooloffPeriod(new \DateTime("2016-01-15"), false));
        $this->assertFalse($policy->isWithinCooloffPeriod(new \DateTime("2016-01-31"), true));
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
            /** @var SCode $scode */
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
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $cancelDate),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $monthlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_COOLOFF, $cancelDate);
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $cancelDate),
            $monthlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION,
            $monthlyPolicy->getRefundCommissionAmount()
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, $cancelDate),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_COOLOFF, $cancelDate);
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, $cancelDate),
            $yearlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            Salva::YEARLY_TOTAL_COMMISSION,
            $yearlyPolicy->getRefundCommissionAmount()
        );
    }

    public function testPolicyWithFreeMonthCooloffNoRefund()
    {
        $cancelDate = new \DateTime('2016-01-10');

        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $cancelDate),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        // add refund of the first month
        self::addPayment(
            $monthlyPolicy,
            0 - static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $cancelDate),
            0 - Salva::MONTHLY_TOTAL_COMMISSION
        );

        $monthlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_COOLOFF, $cancelDate);
        $this->assertEquals(
            0,
            $monthlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            0,
            $monthlyPolicy->getRefundCommissionAmount()
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, $cancelDate),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        // add refund of the first month
        self::addPayment(
            $yearlyPolicy,
            0 - static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $cancelDate),
            0 - Salva::MONTHLY_TOTAL_COMMISSION
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_COOLOFF, $cancelDate);
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, $cancelDate) -
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $cancelDate),
            $yearlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            Salva::YEARLY_TOTAL_COMMISSION - Salva::MONTHLY_TOTAL_COMMISSION,
            $yearlyPolicy->getRefundCommissionAmount()
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
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, new \DateTime('2016-02-10')),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $used = static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, new \DateTime('2016-02-10')) *
            $monthlyPolicy->getProrataMultiplier(new \DateTime('2016-02-10'));
        $paid = static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, new \DateTime('2016-02-10'));
        $this->assertEquals(
            $this->toTwoDp($paid - $used),
            $monthlyPolicy->getProratedPremiumRefund(new \DateTime('2016-02-10'))
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, new \DateTime('2016-02-10')),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $used = static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, new \DateTime('2016-02-10')) *
            $yearlyPolicy->getProrataMultiplier(new \DateTime('2016-02-10'));
        $paid = static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, new \DateTime('2016-02-10'));
        $this->assertEquals(
            $this->toTwoDp($paid - $used),
            $yearlyPolicy->getProratedPremiumRefund(new \DateTime('2016-02-10'))
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
            $this->toTwoDp($used - $paid),
            $monthlyPolicy->getProratedCommissionRefund(new \DateTime('2016-02-10'))
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
            $this->toTwoDp($used - $paid),
            $yearlyPolicy->getProratedCommissionRefund(new \DateTime('2016-02-10'))
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
            $monthlyPolicy->getProratedPremiumRefund(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            $monthlyPolicy->getProratedCommissionRefund(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundCommissionAmount()
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-10'));
        $this->assertEquals(
            $yearlyPolicy->getProratedPremiumRefund(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            $yearlyPolicy->getProratedCommissionRefund(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundCommissionAmount()
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
            $monthlyPolicy->getProratedPremiumRefund(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            $monthlyPolicy->getProratedCommissionRefund(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundCommissionAmount()
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-10'));
        $this->assertEquals(
            $yearlyPolicy->getProratedPremiumRefund(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            $yearlyPolicy->getProratedCommissionRefund(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundCommissionAmount()
        );
    }

    public function testSetPicSureStatus()
    {
        $policy = static::createUserPolicy(true, new \DateTime('2018-01-01'));
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        $this->assertNull($policy->getPicSureApprovedDate());
        $this->assertNull($policy->getPicSureStatus());
        $this->assertTrue($policy->getUser()->getAnalytics()['hasOutstandingPicSurePolicy']);

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_MANUAL);
        $this->assertNull($policy->getPicSureApprovedDate());
        $this->assertTrue($policy->getUser()->getAnalytics()['hasOutstandingPicSurePolicy']);

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_INVALID);
        $this->assertNull($policy->getPicSureApprovedDate());
        $this->assertTrue($policy->getUser()->getAnalytics()['hasOutstandingPicSurePolicy']);

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED);
        $this->assertNull($policy->getPicSureApprovedDate());
        $this->assertFalse($policy->getUser()->getAnalytics()['hasOutstandingPicSurePolicy']);

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $this->assertEquals(
            \DateTime::createFromFormat('U', time()),
            $policy->getPicSureApprovedDate(),
            '',
            1
        );
        $this->assertFalse($policy->getUser()->getAnalytics()['hasOutstandingPicSurePolicy']);
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
            $monthlyPolicy->getProratedPremiumRefund(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            $monthlyPolicy->getProratedCommissionRefund(new \DateTime('2016-02-10')),
            $monthlyPolicy->getRefundCommissionAmount()
        );

        $yearlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION,
            1
        );
        $yearlyPolicy->cancel(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-10'));
        $this->assertEquals(
            $yearlyPolicy->getProratedPremiumRefund(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundAmount()
        );
        $this->assertEquals(
            $yearlyPolicy->getProratedCommissionRefund(new \DateTime('2016-02-10')),
            $yearlyPolicy->getRefundCommissionAmount()
        );
    }

    private function createPolicyForCancellation($amount, $commission, $installments, $date = null, $discount = null)
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

        $discountPayment = null;
        if ($discount) {
            $discountPayment = new PolicyDiscountPayment();
            $discountPayment->setAmount($discount);
            $discountPayment->setDate($date);
            $policy->addPayment($discountPayment);
        }

        $policy->create(rand(1, 999999), null, $date, rand(1, 9999));
        $policy->setPremiumInstallments($installments);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        if ($discount && $discountPayment) {
            $policy->getPremium()->setAnnualDiscount($discountPayment->getAmount());
        }
        if ($amount > 0) {
            self::addPayment($policy, $amount, $commission, null, $date);
        }

        self::$dm->persist($user);
        self::$dm->persist($policy);
        self::$dm->flush();

        return $policy;
    }

    public function testFinalMonthlyPayment()
    {
        $date = new \DateTime('2016-01-01');
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertFalse($monthlyPolicy->isFinalMonthlyPayment());

        for ($i = 1; $i <= 10; $i++) {
            self::addPayment(
                $monthlyPolicy,
                static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
                Salva::MONTHLY_TOTAL_COMMISSION
            );
        }
        $this->assertTrue($monthlyPolicy->isFinalMonthlyPayment());
    }

    public function testOutstandingPremium()
    {
        $date = new \DateTime('2016-01-01');
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date) * 11,
            $monthlyPolicy->getOutstandingPremium()
        );

        for ($i = 1; $i <= 10; $i++) {
            self::addPayment(
                $monthlyPolicy,
                static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
                Salva::MONTHLY_TOTAL_COMMISSION
            );
        }
        $this->assertEquals(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            $monthlyPolicy->getOutstandingPremium()
        );

        self::addPayment(
            $monthlyPolicy,
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION
        );
        $this->assertEquals(0, $monthlyPolicy->getOutstandingPremium());
    }

    public function testInitialPayment()
    {
        $date = new \DateTime('2016-01-01');
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertTrue($monthlyPolicy->isInitialPayment());

        for ($i = 1; $i <= 11; $i++) {
            self::addPayment(
                $monthlyPolicy,
                static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
                Salva::MONTHLY_TOTAL_COMMISSION
            );
            $this->assertFalse($monthlyPolicy->isInitialPayment());
        }
    }

    public function testOutstandingPremiumToDate()
    {
        $date = new \DateTime('2016-01-01');
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertEquals(0, $monthlyPolicy->getOutstandingPremiumToDate($date));
        $this->assertTrue($monthlyPolicy->isValidPolicy(null));

        // billing date is +1 hour, and needs to be slight after
        $date->add(new \DateInterval('PT2H'));

        for ($i = 1; $i <= 11; $i++) {
            $date->add(new \DateInterval('P1M'));
            $this->assertEquals(
                $monthlyPolicy->getPremium()->getMonthlyPremiumPrice() * $i,
                $monthlyPolicy->getOutstandingPremiumToDate($date)
            );
        }
    }

    public function testOutstandingPremiumToDateWithDiscount()
    {
        $date = new \DateTime('2016-01-01');
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getAdjustedStandardMonthlyPremiumPrice(10, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12,
            $date,
            10
        );
        $this->assertEquals(0, $monthlyPolicy->getOutstandingPremiumToDate($date));
        $this->assertTrue($monthlyPolicy->isValidPolicy(null));
        $this->assertTrue($monthlyPolicy->getPremium()->hasAnnualDiscount());
        $this->assertNotEquals(
            $monthlyPolicy->getPremium()->getAdjustedStandardMonthlyPremiumPrice(),
            $monthlyPolicy->getPremium()->getMonthlyPremiumPrice()
        );

        // needs to be just slightly after 1 month
        $date->add(new \DateInterval('P1D'));

        for ($i = 1; $i <= 11; $i++) {
            $date->add(new \DateInterval('P1M'));
            $this->assertEquals(
                $monthlyPolicy->getPremium()->getAdjustedStandardMonthlyPremiumPrice() * $i,
                $monthlyPolicy->getOutstandingPremiumToDate($date),
                sprintf('date: %s, month: %d', $date->format(\DateTime::ATOM), $i)
            );
        }
    }

    public function testTotalExpectedPaidToDate()
    {
        $date = new \DateTime('2016-01-01');
        $monthlyPolicy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $this->assertTrue($monthlyPolicy->isValidPolicy(null));

        // billing date is +1 hour, and needs to be slight after
        $date->add(new \DateInterval('PT2H'));

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
        $date->add(new \DateInterval('P1M'));
        $this->assertEquals(
            $monthlyPolicy->getPremium()->getMonthlyPremiumPrice() * 12,
            $monthlyPolicy->getTotalExpectedPaidToDate($date)
        );

        // even far into the future, max of 12 months payment
        $date->add(new \DateInterval('P1Y'));
        $this->assertEquals(
            $monthlyPolicy->getPremium()->getMonthlyPremiumPrice() * 12,
            $monthlyPolicy->getTotalExpectedPaidToDate($date)
        );
    }

    public function testIsUnRenewalAllowed()
    {
        $policy = new SalvaPhonePolicy();
        $this->assertFalse($policy->isUnRenewalAllowed());

        $policy->setStatus(Policy::STATUS_PENDING_RENEWAL);
        $this->assertFalse($policy->isUnRenewalAllowed());

        $policy->setRenewalExpiration(new \DateTime('2017-09-13 23:59:00'));
        $this->assertTrue($policy->isUnRenewalAllowed());
        $this->assertTrue($policy->isUnRenewalAllowed(new \DateTime('2017-09-14 00:00')));
        $this->assertFalse($policy->isUnRenewalAllowed(new \DateTime('2017-09-13 23:00')));
    }

    /**
     * @expectedException \AppBundle\Exception\InvalidPremiumException
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
        $user->setLeadSourceDetails('foo');
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));

        $policy->init($user, static::getLatestPolicyTerms(self::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $this->assertEquals(Lead::LEAD_SOURCE_SCODE, $policy->getLeadSource());
        $this->assertEquals('foo', $policy->getLeadSourceDetails());
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
        $timezone = new \DateTimeZone('Europe/London');

        $policy = new SalvaPhonePolicy();
        $policy->setPremiumInstallments(12);
        $policy->setStart(new \DateTime('2016-01-15'));
        $this->assertEquals(
            new \DateTime('2016-02-15 00:00', $timezone),
            $policy->getNextBillingDate(new \DateTime('2016-02-14'))
        );
        $this->assertEquals(
            new \DateTime('2016-03-15 00:00', $timezone),
            $policy->getNextBillingDate(new \DateTime('2016-02-16'))
        );

        $policy = new SalvaPhonePolicy();
        $policy->setPremiumInstallments(12);
        $policy->setStart(new \DateTime('2016-03-29'));
        $this->assertEquals(
            new \DateTime('2016-04-28 00:00', $timezone),
            $policy->getNextBillingDate(new \DateTime('2016-04-14'))
        );
        $this->assertEquals(
            new \DateTime('2016-05-28 00:00', $timezone),
            $policy->getNextBillingDate(new \DateTime('2016-04-30'))
        );
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
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        // starts 1/1/16 + 1 month = 1/2/16 + 30 days = 2/3/16
        $timezone = new \DateTimeZone('Europe/London');
        $this->assertEquals(
            new \DateTime('2016-03-02', $timezone),
            $policy->getPolicyExpirationDate()
        );
        $this->assertEquals(1, $policy->getPolicyExpirationDateDays(new \DateTime('2016-03-01')));
        $this->assertEquals(30, $policy->getPolicyExpirationDateDays(new \DateTime('2016-02-01')));

        // add an ontime payment
        self::addPayment(
            $policy,
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-02-01')
        );

        // expected payment 1/2/16 + 1 month = 1/3/16 + 30 days = 31/3/16
        $this->assertEquals(
            new \DateTime('2016-03-31', $timezone),
            $policy->getPolicyExpirationDate()
        );

        // add a late payment
        self::addPayment(
            $policy,
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-03-30')
        );

        // expected payment 1/3/16 + 1 month = 1/4/16 + 30 days = 1/5/16
        $this->assertEquals(
            new \DateTime('2016-05-01', $timezone),
            $policy->getPolicyExpirationDate()
        );
    }

    public function testPolicyExpirationDateYearly()
    {
        $date = new \DateTime('2016-01-01');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, $date),
            Salva::YEARLY_TOTAL_COMMISSION,
            1,
            $date
        );
        $this->assertEquals(new \DateTime('2017-01-01'), $policy->getPolicyExpirationDate(new \DateTime('2016-01-01')));
        $this->assertEquals(new \DateTime('2017-01-01'), $policy->getPolicyExpirationDate(new \DateTime('2016-12-31')));
        $this->assertEquals(new \DateTime('2017-01-01'), $policy->getPolicyExpirationDate(new \DateTime('2017-01-01')));
    }

    /**
     * @expectedException \Exception
     */
    public function testPolicyExpirationDateYearlyInvalidAmount()
    {
        $date = new \DateTime('2016-01-01');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, $date) - 1,
            Salva::YEARLY_TOTAL_COMMISSION,
            1,
            $date
        );
        $this->assertEquals(new \DateTime('2017-01-01'), $policy->getPolicyExpirationDate(new \DateTime('2016-01-01')));
    }

    public function testCanBacsPaymentBeMadeInTime()
    {
        $date = new \DateTime('2018-09-01');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12,
            $date
        );
        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_PENDING_APPROVAL);

        $this->assertEquals(new \DateTime('2018-10-31'), $policy->getPolicyExpirationDate(new \DateTime('2018-09-01')));
        $this->assertTrue($policy->canBacsPaymentBeMadeInTime(new \DateTime('2018-10-21')));
        $this->assertFalse($policy->canBacsPaymentBeMadeInTime(new \DateTime('2018-10-22')));
    }

    public function testPolicyRenewalUnpaidExpirationDateYearly()
    {
        $date = new \DateTime('2016-01-01');
        $policy = $this->createPolicyForCancellation(
            0,
            0,
            1,
            $date
        );
        $this->assertEquals(new \DateTime('2016-01-31'), $policy->getPolicyExpirationDate(new \DateTime('2016-01-01')));
        $this->assertEquals(new \DateTime('2016-01-31'), $policy->getPolicyExpirationDate(new \DateTime('2016-01-31')));
        $this->assertEquals(new \DateTime('2016-01-31'), $policy->getPolicyExpirationDate(new \DateTime('2016-02-01')));
    }

    public function testPolicyIsPaidToDateStandard()
    {
        $date = new \DateTime('2016-01-01 10:00');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12,
            $date
        );
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-01-31 09:00')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-01-31 11:00')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-02-01 00:01')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-02-01 11:01')));

        // add an ontime payment
        self::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-02-01 10:00')
        );

        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-02-01 00:01')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-02-01 10:01')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-03-01 00:01')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-03-01 10:01')));

        // add a late payment
        self::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-03-30 10:00')
        );

        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-03-01 09:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-03-01 11:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-03-30 09:00')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-03-30 11:00')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-04-01 00:01')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-04-01 11:01')));
    }

    public function testPolicyIsPaidToDate30()
    {
        $date = new \DateTime('2016-01-30 10:00');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12,
            $date
        );
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-01-31 00:01')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-02-01 11:00')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-02-28 09:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-02-28 11:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-03-01')));

        // add an ontime payment
        self::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-02-28 10:00')
        );

        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-03-01')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-03-28 09:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-03-28 11:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-04-01')));

        // add a late payment
        self::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-04-15')
        );

        // we don't actually check when payment arrives, just that its there...
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-04-01 11:00')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-04-15 00:01')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-04-28 09:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-04-28 11:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-05-01 00:01')));
    }

    public function testPolicyIsPaidToDate28()
    {
        $date = new \DateTime('2016-01-28 15:00');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12,
            $date
        );
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-01-31 00:01')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-02-01')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-02-28 14:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-02-28 16:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-03-01')));

        // add an ontime payment
        self::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-02-28 15:00')
        );

        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-03-01')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-03-28 14:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-03-28 16:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-04-01')));

        // add a late payment
        self::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-04-15 15:00')
        );

        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-04-15 00:01')));
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-04-15 15:01')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-04-28 16:01')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2016-05-01 00:01')));
    }

    public function testPolicyIsPaidToDateDiscount()
    {
        $date = new \DateTime('2018-02-27 00:00');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getAdjustedStandardMonthlyPremiumPrice(15, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12,
            $date,
            15
        );
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2018-02-27 00:00')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2018-03-31 00:00')));
    }

    public function testHasCorrectPolicyStatus()
    {
        $policy = new SalvaPhonePolicy();
        $this->assertNull($policy->hasCorrectPolicyStatus());

        $date = new \DateTime('2016-01-01');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12
        );
        $policy->setStatus(SalvaPhonePolicy::STATUS_PENDING);
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
            SalvaPhonePolicy::STATUS_EXPIRED_CLAIMABLE,
            SalvaPhonePolicy::STATUS_EXPIRED_WAIT_CLAIM,
            SalvaPhonePolicy::STATUS_MULTIPAY_REJECTED,
            SalvaPhonePolicy::STATUS_MULTIPAY_REQUESTED
        ];
        foreach ($ignoredStatuses as $status) {
            $policy->setStatus($status);
            $this->assertNull($policy->hasCorrectPolicyStatus());
        }

        $policy->setStatus(SalvaPhonePolicy::STATUS_RENEWAL);
        $this->assertTrue($policy->hasCorrectPolicyStatus(new \DateTime('2015-12-30')));
        $this->assertFalse($policy->hasCorrectPolicyStatus($date));
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

        // test is if the above generates an exception
        $this->assertTrue(true);
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

    public function testIsRefundAllowed()
    {
        $policy = new SalvaPhonePolicy();
        $allowedRefunds = [
            Policy::CANCELLED_USER_REQUESTED,
            Policy::CANCELLED_COOLOFF,
            Policy::CANCELLED_DISPOSSESSION,
            Policy::CANCELLED_WRECKAGE,
        ];
        $upgrade = Policy::CANCELLED_UPGRADE;
        $disallowedRefunds = [
            Policy::CANCELLED_UNPAID,
            Policy::CANCELLED_ACTUAL_FRAUD,
            Policy::CANCELLED_SUSPECTED_FRAUD,
        ];
        foreach ($allowedRefunds as $reason) {
            $policy->setCancelledReason($reason);
            $this->assertTrue($policy->isRefundAllowed());
        }
        foreach ($disallowedRefunds as $reason) {
            $policy->setCancelledReason($reason);
            $this->assertFalse($policy->isRefundAllowed());
        }

        $policy->setCancelledReason($upgrade);
        $this->assertTrue($policy->isRefundAllowed());

        $claim = new Claim();
        $claim->setRecordedDate(new \DateTime("2016-01-01"));
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setClosedDate(new \DateTime("2016-01-01"));
        $policy->addClaim($claim);

        $policy->setCancelledReason($upgrade);
        $this->assertTrue($policy->isRefundAllowed());
        foreach ($allowedRefunds as $reason) {
            $policy->setCancelledReason($reason);
            $this->assertFalse($policy->isRefundAllowed());
        }
        foreach ($disallowedRefunds as $reason) {
            $policy->setCancelledReason($reason);
            $this->assertFalse($policy->isRefundAllowed());
        }
    }

    public function testIsInRenewalTimeframe()
    {
        $policy = $this->getPolicy(static::generateEmail('testIsReadyForRenewal', $this));

        // 21 day renewal
        $this->assertTrue($policy->isInRenewalTimeframe(new \DateTime("2016-12-10")));
        $this->assertTrue($policy->isInRenewalTimeframe(new \DateTime("2016-12-31 23:59")));
        $this->assertFalse($policy->isInRenewalTimeframe(new \DateTime("2017-01-01 00:01")));
        $this->assertFalse($policy->isInRenewalTimeframe(new \DateTime("2017-12-01")));

        $policy->setStatus(Policy::STATUS_CANCELLED);
        $this->assertFalse($policy->isInRenewalTimeframe(new \DateTime("2016-12-31 23:59")));
    }

    public function testCanCreatePendingRenewal()
    {
        $policy = $this->getPolicy(static::generateEmail('testCanCreatePendingRenewal', $this));

        // 21 day renewal
        $this->assertTrue($policy->canCreatePendingRenewal(new \DateTime("2016-12-10")));
        $this->assertFalse($policy->canCreatePendingRenewal(new \DateTime("2017-01-01 00:01")));

        $policy->getUser()->setLocked(true);
        $this->assertFalse($policy->canCreatePendingRenewal(new \DateTime("2016-12-10")));
        $policy->getUser()->setLocked(false);

        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_DISPOSSESSION);
        $this->assertFalse($policy->canCreatePendingRenewal(new \DateTime("2016-12-10")));

        $policy->setStatus(SalvaPhonePolicy::STATUS_UNPAID);
        $this->assertTrue($policy->canCreatePendingRenewal(new \DateTime("2016-12-10")));
    }

    public function testCanCreatePendingRenewalInvalidImei()
    {
        $policy = $this->getPolicy(static::generateEmail('testCanCreatePendingRenewalInvalidImei', $this));

        // 21 day renewal
        $this->assertTrue($policy->canCreatePendingRenewal(new \DateTime("2016-12-10")));
        $this->assertFalse($policy->canCreatePendingRenewal(new \DateTime("2017-01-01 00:01")));

        $policy->setInvalidImei(true);
        $this->assertFalse($policy->canCreatePendingRenewal(new \DateTime("2016-12-10")));
    }

    public function testCanRenew()
    {
        $policy = $this->getPolicy(static::generateEmail('testCanRenew', $this));

        $this->assertFalse($policy->canRenew(new \DateTime("2016-12-10")));

        $this->getRenewalPolicy($policy);

        $this->assertTrue($policy->canRenew(new \DateTime("2016-12-10")));

        $policy->getUser()->setLocked(true);
        $this->assertFalse($policy->canRenew(new \DateTime("2016-12-10")));
        $policy->getUser()->setLocked(false);

        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_DISPOSSESSION);
        $this->assertFalse($policy->canRenew(new \DateTime("2016-12-10")));

        $policy->setStatus(SalvaPhonePolicy::STATUS_UNPAID);
        $this->assertTrue($policy->canRenew(new \DateTime("2016-12-10")));
    }

    public function testCanRenewInvalidImei()
    {
        $policy = $this->getPolicy(static::generateEmail('testCanRenewInvalidImei', $this));

        $this->getRenewalPolicy($policy);
        $this->assertTrue($policy->canRenew(new \DateTime("2016-12-10")));

        $policy->setInvalidImei(true);
        $this->assertFalse($policy->canRenew(new \DateTime("2016-12-10")));
    }

    public function testIsRenewed()
    {
        $policy = $this->getPolicy(static::generateEmail('testIsRenewed', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy);

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy->setStatus(Policy::STATUS_RENEWAL);
        $this->assertTrue($policy->isRenewed());
    }

    public function testDeclineRenew()
    {
        $policy = $this->getPolicy(static::generateEmail('testDeclineRenew', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy);
        $renewalPolicy->declineRenew();
        $this->assertEquals(Policy::STATUS_DECLINED_RENEWAL, $renewalPolicy->getStatus());
    }

    public function testDeclineRenewThenRenew()
    {
        $policy = $this->getPolicy(static::generateEmail('testDeclineRenewThenRenew', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy);
        $renewalPolicy->declineRenew();
        $this->assertEquals(Policy::STATUS_DECLINED_RENEWAL, $renewalPolicy->getStatus());

        // should be able to renew after declining if still within timeframe
        $renewalPolicy->renew(0, false, new \DateTime('2016-12-31'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicy->getStatus());
    }

    /**
     * @expectedException \Exception
     */
    public function testDeclineRenewInvalidStatus()
    {
        $policy = $this->getPolicy(static::generateEmail('testDeclineRenew', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy);
        $renewalPolicy->setStatus(Policy::STATUS_RENEWAL);
        $renewalPolicy->declineRenew();
        $this->assertEquals(Policy::STATUS_DECLINED_RENEWAL, $renewalPolicy->getStatus());
    }

    public function testGetUnconnectedUserPolicies()
    {
        $policy = $this->getPolicy(static::generateEmail('testGetUnconnectedUserPolicies', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy);

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy->renew(0, false, new \DateTime('2016-12-15'));
        $this->assertTrue($policy->isRenewed());
        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();
        $this->assertEquals(1, count($renewalPolicy->getUser()->getValidPolicies()));

        $this->assertEquals(0, count($policy->getUnconnectedUserPolicies()));
        $this->assertEquals(0, count($renewalPolicy->getUnconnectedUserPolicies()));
    }

    public function testGetUnconnectedUserPoliciesCanConnect()
    {
        $policyA = $this->getPolicy(static::generateEmail('testGetUnconnectedUserPoliciesCanConnect', $this));
        $policyB = new SalvaPhonePolicy();
        $policyB->setPhone(static::$phone);
        $policyB->init($policyA->getUser(), static::getLatestPolicyTerms(self::$dm));

        $policyB->create(rand(1, 999999), null, new \DateTime('2016-01-01'), rand(1, 9999));
        $policyB->setStatus(Policy::STATUS_ACTIVE);

        static::$dm->persist($policyA);
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB);
        static::$dm->flush();
        $this->assertEquals(2, count($policyA->getUser()->getValidPolicies()));

        $this->assertEquals(1, count($policyA->getUnconnectedUserPolicies()));
        $this->assertEquals(1, count($policyB->getUnconnectedUserPolicies()));
    }

    public function testCanRepurchase()
    {
        $policy = $this->getPolicy(static::generateEmail('testCanRepurchase', $this));

        $this->assertFalse($policy->canRepurchase());

        $renewalPolicy = $this->getRenewalPolicy($policy);

        $this->assertTrue($policy->hasNextPolicy());
        $this->assertFalse($policy->isRenewed());
        $this->assertFalse($policy->canRepurchase());

        $renewalPolicy->setStatus(Policy::STATUS_RENEWAL);

        $this->assertFalse($policy->canRepurchase());

        $renewalPolicy->setStatus(Policy::STATUS_UNRENEWED);

        $policy->getUser()->setLocked(true);
        $this->assertFalse($policy->canRepurchase());
        $policy->getUser()->setLocked(false);

        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_DISPOSSESSION);
        $this->assertFalse($policy->canRepurchase());

        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_COOLOFF);
        $this->assertTrue($policy->canRepurchase());
    }

    public function testDisplayRepurchase()
    {
        $policy = $this->getPolicy(static::generateEmail('testDisplayRepurchase', $this));
        $policy2 = $this->getPolicy(static::generateEmail('testDisplayRepurchase2', $this));

        $this->assertFalse($policy->displayRepurchase());

        $renewalPolicy = $this->getRenewalPolicy($policy);

        $this->assertTrue($policy->hasNextPolicy());
        $this->assertFalse($policy->isRenewed());
        $this->assertFalse($policy->displayRepurchase());

        $renewalPolicy->setStatus(Policy::STATUS_RENEWAL);

        $this->assertFalse($policy->displayRepurchase());

        $policy->setStatus(SalvaPhonePolicy::STATUS_EXPIRED_CLAIMABLE);
        $renewalPolicy->setStatus(Policy::STATUS_UNRENEWED);
        $this->assertTrue($policy->displayRepurchase());

        $policy->getUser()->setLocked(true);
        $this->assertFalse($policy->displayRepurchase());
        $policy->getUser()->setLocked(false);

        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_DISPOSSESSION);
        $this->assertFalse($policy->displayRepurchase());

        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_COOLOFF);
        $this->assertTrue($policy->displayRepurchase());

        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);
        $this->assertTrue($policy->displayRepurchase());

        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_UNPAID);
        $this->assertTrue($policy->displayRepurchase());

        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setIgnoreWarningFlags(Claim::WARNING_FLAG_IGNORE_USER_DECLINED);
        $policy->addClaim($claim);
        $this->assertFalse($policy->isCancelledWithUserDeclined());
        $this->assertTrue($policy->displayRepurchase());

        $policy2->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy2->setCancelledReason(SalvaPhonePolicy::CANCELLED_UNPAID);
        $this->assertTrue($policy2->displayRepurchase());

        $claim2 = new Claim();
        $claim2->setStatus(Claim::STATUS_WITHDRAWN);
        $claim2->setIgnoreWarningFlags(Claim::WARNING_FLAG_IGNORE_USER_DECLINED);
        $policy2->addClaim($claim2);
        $this->assertFalse($policy2->isCancelledWithUserDeclined());
        $this->assertTrue($policy2->displayRepurchase());
    }

    public function testCreateRepurchase()
    {
        $policy = $this->getPolicy(static::generateEmail('testCreateRepurchase', $this));
        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);
        $repurchase = $policy->createRepurchase($policy->getPolicyTerms());

        $this->assertEquals($policy->getImei(), $repurchase->getImei());
        $this->assertEquals($policy->getSerialNumber(), $repurchase->getSerialNumber());
        $this->assertEquals($policy->getUser()->getId(), $repurchase->getUser()->getId());
        $this->assertEquals($policy->getPhone()->getId(), $repurchase->getPhone()->getId());
        $this->assertNull($repurchase->getStatus());
    }

    public function testCreateRepurchaseCancelledUnpaidClaims()
    {
        $policy = $this->getPolicy(static::generateEmail('testCreateRepurchaseCancelledUnpaidClaims', $this));
        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);
        $policy->setId(rand(1, 999999));
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setIgnoreWarningFlags(Claim::WARNING_FLAG_IGNORE_USER_DECLINED);

        $policy->addClaim($claim);
        $this->assertTrue($policy->isCancelledAndPaymentOwed());

        $repurchase = $policy->createRepurchase($policy->getPolicyTerms());
        $repurchase->setId(rand(1, 999999));

        $this->assertNull($repurchase->getStatus());
        $this->assertNotNull($claim->getLinkedPolicy());
        $this->assertEquals($repurchase->getId(), $claim->getLinkedPolicy()->getId());
    }

    public function testAutoRenewWhenCancelled()
    {
        $policy = $this->getPolicy(static::generateEmail('testRenewPicSurePreApproved', $this));
        $policy->setId(rand(1, 9999999));

        $this->assertFalse($policy->isRenewed());
        $this->assertNull($policy->getPicSureStatus());
        $this->assertTrue($policy->getUser()->getAnalytics()['hasOutstandingPicSurePolicy']);

        $renewalPolicy = $this->getRenewalPolicy($policy);

        $this->assertFalse($policy->isRenewed());
        $this->assertTrue($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-15')));

        $policy->cancel(Policy::CANCELLED_USER_REQUESTED, new \DateTime('2016-12-30'));

        $this->assertFalse($renewalPolicy->renew(0, true, new \DateTime('2017-01-01')));

        $this->assertFalse($policy->isRenewed());
    }

    public function testRenewPicSurePreApproved()
    {
        $policy = $this->getPolicy(static::generateEmail('testRenewPicSurePreApproved', $this));

        $this->assertFalse($policy->isRenewed());
        $this->assertNull($policy->getPicSureStatus());
        $this->assertTrue($policy->getUser()->getAnalytics()['hasOutstandingPicSurePolicy']);

        $renewalPolicy = $this->getRenewalPolicy($policy);

        $this->assertFalse($policy->isRenewed());
        $this->assertTrue($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-15')));

        $renewalPolicy->renew(0, false, new \DateTime('2016-12-15'));

        $this->assertTrue($policy->isRenewed());
        $this->assertTrue($policy->getPolicyTerms()->isPicSureEnabled());
        $this->assertEquals(PhonePolicy::PICSURE_STATUS_PREAPPROVED, $renewalPolicy->getPicSureStatus());
    }

    public function testRenewActivateExpire()
    {
        $policy = $this->getPolicy(static::generateEmail('testRenewActivateExpire', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy);

        $this->assertFalse($policy->isRenewed());
        $this->assertTrue($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-15')));

        $renewalPolicy->renew(0, false, new \DateTime('2016-12-15'));

        $this->assertTrue($policy->isRenewed());

        $policy->expire(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policy->getStatus());

        $renewalPolicy->activate(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicy->getStatus());

        $policy->fullyExpire(new \DateTime("2017-01-29"));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policy->getStatus());
        foreach ($policy->getPayments() as $payment) {
            $this->assertFalse($payment instanceof PotRewardPayment);
            $this->assertFalse($payment instanceof PolicyDiscountPayment);
        }
    }

    public function testRenewActivateExpireWithClaim()
    {
        $policy = $this->getPolicy(static::generateEmail('testRenewActivateExpireWithClaim', $this));

        $this->assertFalse($policy->isRenewed());

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);

        $renewalPolicy = $this->getRenewalPolicy($policy);

        $this->assertFalse($policy->isRenewed());
        $this->assertTrue($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-15')));

        $renewalPolicy->renew(0, false, new \DateTime('2016-12-15'));

        $this->assertTrue($policy->isRenewed());

        $policy->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policy->getStatus());

        $renewalPolicy->activate(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicy->getStatus());

        $policy->fullyExpire(new \DateTime("2017-01-29"));
        $this->assertEquals(Policy::STATUS_EXPIRED_WAIT_CLAIM, $policy->getStatus());

        $claim->setStatus(Claim::STATUS_SETTLED);
        $foundException = false;
        try {
            $policy->fullyExpire(new \DateTime("2017-02-30"));
        } catch (\Exception $e) {
            $foundException = true;
        }
        $this->assertTrue($foundException);

        $claim->setProcessed(true);
        $policy->fullyExpire(new \DateTime("2017-02-30"));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policy->getStatus());

        foreach ($policy->getPayments() as $payment) {
            $this->assertFalse($payment instanceof PotRewardPayment);
            $this->assertFalse($payment instanceof PolicyDiscountPayment);
        }
    }

    public function testRenewActivateExpireWithMonitaryClaim()
    {
        $policy = $this->getPolicy(static::generateEmail('testRenewActivateExpireWithMonitaryClaim', $this));

        $this->assertFalse($policy->isRenewed());

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setProcessed(true);
        $policy->addClaim($claim);

        $renewalPolicy = $this->getRenewalPolicy($policy);

        $this->assertFalse($policy->isRenewed());
        $this->assertTrue($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-15')));

        $renewalPolicy->renew(0, false, new \DateTime('2016-12-15'));

        $this->assertTrue($policy->isRenewed());

        $policy->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policy->getStatus());

        $renewalPolicy->activate(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicy->getStatus());

        $policy->fullyExpire(new \DateTime("2017-01-29"));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policy->getStatus());

        foreach ($policy->getPayments() as $payment) {
            $this->assertFalse($payment instanceof PotRewardPayment);
            $this->assertFalse($payment instanceof PolicyDiscountPayment);
        }
    }

    public function testRenewActivateExpireWithPot()
    {
        $policyA = $this->getPolicy(static::generateEmail('testRenewActivateExpireWithPot-A', $this));
        $policyB = $this->getPolicy(static::generateEmail('testRenewActivateExpireWithPot-B', $this));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertFalse($policyA->isRenewed());

        $renewalPolicyA = $this->getRenewalPolicy($policyA);

        $this->assertFalse($policyA->isRenewed());
        $this->assertTrue($renewalPolicyA->isRenewalAllowed(false, new \DateTime('2016-12-15')));

        $renewalPolicyA->renew(10, false, new \DateTime('2016-12-15'));

        $this->assertTrue($policyA->isRenewed());

        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $renewalPolicyA->activate(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());
        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        $policyA->fullyExpire(new \DateTime("2017-01-29"));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        $foundReward = false;
        foreach ($policyA->getAllPayments() as $payment) {
            if ($payment instanceof PotRewardPayment) {
                $this->assertEquals(-10, $payment->getAmount());
                $foundReward = true;
            }
        }

        $this->assertTrue($foundReward);

        $foundRenewalDiscount = false;
        foreach ($renewalPolicyA->getPayments() as $payment) {
            if ($payment instanceof PolicyDiscountPayment) {
                $this->assertEquals(10, $payment->getAmount());
                $foundRenewalDiscount = true;
            }
        }

        $this->assertTrue($foundRenewalDiscount);
    }

    public function testRenewActivateExpireWithPotPromo()
    {
        $policyA = $this->getPolicy(static::generateEmail('testRenewActivateExpireWithPotPromo-A', $this));
        $policyB = $this->getPolicy(static::generateEmail('testRenewActivateExpireWithPotPromo-B', $this));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 15, 15);

        $this->assertEquals(15, $policyA->getPotValue());
        $this->assertFalse($policyA->isRenewed());

        $renewalPolicyA = $this->getRenewalPolicy($policyA);

        $this->assertFalse($policyA->isRenewed());
        $this->assertTrue($renewalPolicyA->isRenewalAllowed(false, new \DateTime('2016-12-15')));

        $renewalPolicyA->renew(15, false, new \DateTime('2016-12-15'));

        $this->assertTrue($policyA->isRenewed());

        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $renewalPolicyA->activate(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());
        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        $policyA->fullyExpire(new \DateTime("2017-01-29"));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        $foundReward = false;
        $foundSoSureReward = false;
        foreach ($policyA->getAllPayments() as $payment) {
            if ($payment instanceof PotRewardPayment) {
                $this->assertEquals(-10, $payment->getAmount());
                // only expected once
                $this->assertFalse($foundReward);
                $foundReward = true;
            }
            if ($payment instanceof SoSurePotRewardPayment) {
                $this->assertEquals(-5, $payment->getAmount());
                // only expected once
                $this->assertFalse($foundSoSureReward);
                $foundSoSureReward = true;
            }
        }

        $this->assertTrue($foundReward);
        $this->assertTrue($foundSoSureReward);

        $foundRenewalDiscount = false;
        foreach ($renewalPolicyA->getPayments() as $payment) {
            if ($payment instanceof PolicyDiscountPayment) {
                $this->assertEquals(15, $payment->getAmount());
                $foundRenewalDiscount = true;
            }
        }

        $this->assertTrue($foundRenewalDiscount);
    }

    public function testAutoRenewActivateExpireWithPot()
    {
        $policyA = $this->getPolicy(static::generateEmail('testAutoRenewActivateExpireWithPot-A', $this));
        $policyB = $this->getPolicy(static::generateEmail('testAutoRenewActivateExpireWithPot-B', $this));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertFalse($policyA->isRenewed());

        $renewalPolicyA = $this->getRenewalPolicy($policyA);

        $this->assertFalse($policyA->isRenewed());
        $this->assertTrue($renewalPolicyA->isRenewalAllowed(false, new \DateTime('2016-12-15')));

        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $renewalPolicyA->renew(10, true, new \DateTime('2017-01-01'));
        $this->assertTrue($policyA->isRenewed());

        $renewalPolicyA->activate(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());
        $this->assertEquals(10, $renewalPolicyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $policyA->fullyExpire(new \DateTime("2017-01-29"));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        $foundReward = false;
        foreach ($policyA->getAllPayments() as $payment) {
            if ($payment instanceof PotRewardPayment) {
                $this->assertEquals(-10, $payment->getAmount());
                // expected only once
                $this->assertFalse($foundReward);
                $foundReward = true;
            }
        }

        $this->assertTrue($foundReward);

        $foundRenewalDiscount = false;
        foreach ($renewalPolicyA->getPayments() as $payment) {
            if ($payment instanceof PolicyDiscountPayment) {
                $this->assertEquals(10, $payment->getAmount());
                $foundRenewalDiscount = true;
            }
        }

        $this->assertTrue($foundRenewalDiscount);
    }

    public function testDiscountExpiredWithClaim()
    {
        $policyA = $this->getPolicy(static::generateEmail('testDiscountExpiredWithClaim-A', $this));
        $policyB = $this->getPolicy(static::generateEmail('testDiscountExpiredWithClaim-B', $this));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $this->assertFalse($policyA->isRenewed());

        $renewalPolicyA = $this->getRenewalPolicy($policyA);

        $this->assertFalse($policyA->isRenewed());
        $this->assertTrue($renewalPolicyA->isRenewalAllowed(false, new \DateTime('2016-12-15')));

        $renewalPolicyA->renew(10, false, new \DateTime('2016-12-15'));

        $this->assertTrue($policyA->isRenewed());

        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $renewalPolicyA->activate(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $claimA = new Claim();
        $claimA->setType(Claim::TYPE_LOSS);
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $claimA->setProcessed(true);
        $policyA->addClaim($claimA);

        $policyA->fullyExpire(new \DateTime("2017-01-29"));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
        $foundReward = false;
        $foundRefund = false;
        foreach ($policyA->getAllPayments() as $payment) {
            if ($payment instanceof PotRewardPayment && $payment->getAmount() == -10) {
                // expected only once
                $this->assertFalse($foundReward);
                $foundReward = true;
            }
            if ($payment instanceof PotRewardPayment && $payment->getAmount() == 10) {
                // expected only once
                $this->assertFalse($foundRefund);
                $foundRefund = true;
            }
        }

        $this->assertTrue($foundReward);
        $this->assertTrue($foundRefund);

        $foundRenewalDiscount = false;
        $foundRenewalRefund = false;
        foreach ($renewalPolicyA->getPayments() as $payment) {
            if ($payment instanceof PolicyDiscountPayment && $payment->getAmount() == 10) {
                // expected only once
                $this->assertFalse($foundRenewalDiscount);
                $foundRenewalDiscount = true;
            }
            if ($payment instanceof PolicyDiscountPayment && $payment->getAmount() == -10) {
                // expected only once
                $this->assertFalse($foundRenewalRefund);
                $foundRenewalRefund = true;
            }
        }

        $this->assertTrue($foundRenewalDiscount);
        $this->assertTrue($foundRenewalRefund);
    }

    public function testClaimExpiredWithClaim()
    {
        $policyA = $this->getPolicy(static::generateEmail('testCashbackExpiredWithClaim-A', $this));
        $policyB = $this->getPolicy(static::generateEmail('testCashbackExpiredWithClaim-B', $this));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $this->assertFalse($policyA->isRenewed());

        $renewalPolicyA = $this->getRenewalPolicy($policyA);

        $this->assertFalse($policyA->isRenewed());
        $this->assertTrue($renewalPolicyA->isRenewalAllowed(false, new \DateTime('2016-12-15')));

        $cashback = new Cashback();
        $cashback->setDate(new \DateTime('2016-12-15'));
        $cashback->setStatus(Cashback::STATUS_MISSING);
        $cashback->setAmount(10);
        $policyA->setCashback($cashback);
        $renewalPolicyA->renew(0, false, new \DateTime('2016-12-15'));

        $this->assertTrue($policyA->isRenewed());

        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $renewalPolicyA->activate(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertNotNull($policyA->getCashback());
        $this->assertEquals(10, $policyA->getCashback()->getAmount());

        $claimA = new Claim();
        $claimA->setType(Claim::TYPE_LOSS);
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $claimA->setProcessed(true);
        $policyA->addClaim($claimA);

        $policyA->fullyExpire(new \DateTime("2017-01-29"));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        $this->assertEquals(0, $policyA->getCashback()->getAmount());

        $foundReward = false;
        $foundRefund = false;
        foreach ($policyA->getAllPayments() as $payment) {
            if ($payment instanceof PotRewardPayment && $payment->getAmount() == -10) {
                $foundReward = true;
            }
            if ($payment instanceof PotRewardPayment && $payment->getAmount() == 10) {
                $foundRefund = true;
            }
        }

        $this->assertTrue($foundReward);
        $this->assertTrue($foundRefund);

        $foundRenewalDiscount = false;
        $foundRenewalRefund = false;
        foreach ($renewalPolicyA->getPayments() as $payment) {
            if ($payment instanceof PolicyDiscountPayment && $payment->getAmount() == 10) {
                // expected only once
                $this->assertFalse($foundRenewalDiscount);
                $foundRenewalDiscount = true;
            }
            if ($payment instanceof PolicyDiscountPayment && $payment->getAmount() == -10) {
                // expected only once
                $this->assertFalse($foundRenewalRefund);
                $foundRenewalRefund = true;
            }
        }

        $this->assertFalse($foundRenewalDiscount);
        $this->assertFalse($foundRenewalRefund);
    }

    public function testRenewActivateExpireWithoutPot()
    {
        $policyA = $this->getPolicy(static::generateEmail('testRenewActivateExpireWithoutPot-A', $this));
        $policyB = $this->getPolicy(static::generateEmail('testRenewActivateExpireWithoutPot-B', $this));
        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $this->assertFalse($policyA->isRenewed());

        $renewalPolicyA = $this->getRenewalPolicy($policyA);

        $this->assertFalse($policyA->isRenewed());
        $this->assertTrue($renewalPolicyA->isRenewalAllowed(false, new \DateTime('2016-12-15')));

        $renewalPolicyA->renew(0, false, new \DateTime('2016-12-15'));

        $this->assertTrue($policyA->isRenewed());

        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $renewalPolicyA->activate(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $policyA->fullyExpire(new \DateTime("2017-01-29"));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
        $foundReward = false;
        $foundDiscount = false;
        foreach ($policyA->getAllPayments() as $payment) {
            if ($payment instanceof PotRewardPayment) {
                $this->assertEquals(-10, $payment->getAmount());
                // expected only once
                $this->assertFalse($foundReward);
                $foundReward = true;
            }
            if ($payment instanceof PolicyDiscountPayment) {
                // expected only once
                $this->assertFalse($foundDiscount);
                $foundDiscount = true;
            }
        }

        $this->assertTrue($foundReward);
        $this->assertFalse($foundDiscount);

        $foundRenewalDiscount = false;
        foreach ($renewalPolicyA->getPayments() as $payment) {
            if ($payment instanceof PolicyDiscountPayment) {
                $foundDiscount = true;
            }
        }

        $this->assertFalse($foundRenewalDiscount);
    }

    public function testPendingRenewalExpiration()
    {
        $policy = $this->getPolicy(static::generateEmail('testPendingRenewalExpiration', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy, false);
        $this->assertEquals(new \DateTime('2016-12-31 23:59:59'), $renewalPolicy->getRenewalExpiration());

        $this->assertTrue($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-31 23:59')));
        $this->assertFalse($renewalPolicy->isRenewalAllowed(false, new \DateTime('2017-01-01')));
        $this->assertTrue($renewalPolicy->isRenewalAllowed(true, new \DateTime('2017-01-07')));
        $this->assertFalse($renewalPolicy->isRenewalAllowed(true, new \DateTime('2017-01-08')));

        $renewalPolicy->renew(0, false, new \DateTime('2016-12-15'));
        $this->assertNull($renewalPolicy->getRenewalExpiration());
    }

    public function testUnRenew()
    {
        $policy = $this->getPolicy(static::generateEmail('testUnRenew', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy, false);
        $this->assertEquals(new \DateTime('2016-12-31 23:59:59'), $renewalPolicy->getRenewalExpiration());

        $this->assertTrue($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-31 23:59')));
        $this->assertFalse($renewalPolicy->isRenewalAllowed(false, new \DateTime('2017-01-01')));

        $renewalPolicy->unrenew(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_UNRENEWED, $renewalPolicy->getStatus());
    }

    /**
     * @expectedException \Exception
     */
    public function testUnRenewThenCreate()
    {
        $policy = $this->getPolicy(static::generateEmail('testUnRenewThenCreate', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy, false);
        $this->assertEquals(new \DateTime('2016-12-31 23:59:59'), $renewalPolicy->getRenewalExpiration());

        $this->assertTrue($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-31 23:59')));
        $this->assertFalse($renewalPolicy->isRenewalAllowed(false, new \DateTime('2017-01-01')));

        $renewalPolicy->unrenew(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_UNRENEWED, $renewalPolicy->getStatus());

        $renewalPolicy->create(rand(1, 999999), null, null, rand(1, 9999));
    }

    /**
     * @expectedException \Exception
     */
    public function testUnRenewalTooEarly()
    {
        $policy = $this->getPolicy(static::generateEmail('testUnRenewalTooEarly', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy, false);
        $this->assertEquals(new \DateTime('2016-12-31 23:59:59'), $renewalPolicy->getRenewalExpiration());

        $this->assertTrue($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-31 23:59')));
        $this->assertFalse($renewalPolicy->isRenewalAllowed(false, new \DateTime('2017-01-01')));

        $renewalPolicy->unrenew(new \DateTime('2016-12-15'));
    }

    public function testIssueDate()
    {
        $policy = new SalvaPhonePolicy();
        $this->assertNull($policy->getIssueDate());
        $policy->setStart(new \DateTime('2016-01-01'));
        $this->assertEquals(new \DateTime('2016-01-01'), $policy->getIssueDate());

        $policy->setIssueDate(new \DateTime('2017-01-01'));
        $this->assertEquals(new \DateTime('2017-01-01'), $policy->getIssueDate());
    }

    /**
     * @expectedException \Exception
     */
    public function testUnRenewalInvalidStatus()
    {
        $policy = $this->getPolicy(static::generateEmail('testUnRenewalInvalidStatus', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy, false);
        $this->assertEquals(new \DateTime('2016-12-31 23:59:59'), $renewalPolicy->getRenewalExpiration());

        $renewalPolicy->setStatus(Policy::STATUS_RENEWAL);

        $renewalPolicy->unrenew(new \DateTime('2017-01-01'));
    }

    /**
     * @expectedException \Exception
     */
    public function testUnRenewedFailsRenew()
    {
        $policy = $this->getPolicy(static::generateEmail('testUnRenewedFailsRenew', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy, false);
        $this->assertEquals(new \DateTime('2016-12-31 23:59:59'), $renewalPolicy->getRenewalExpiration());

        $this->assertTrue($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-31 23:59')));
        $this->assertFalse($renewalPolicy->isRenewalAllowed(false, new \DateTime('2017-01-01')));

        $renewalPolicy->unrenew(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_UNRENEWED, $renewalPolicy->getStatus());

        $renewalPolicy->renew(0, false, new \DateTime('2017-12-15'));
    }

    /**
     * @expectedException \Exception
     */
    public function testUnRenewedFailsActive()
    {
        $policy = $this->getPolicy(static::generateEmail('testUnRenewedFailsRenew', $this));

        $this->assertFalse($policy->isRenewed());

        $renewalPolicy = $this->getRenewalPolicy($policy, false);
        $this->assertEquals(new \DateTime('2016-12-31 23:59:59'), $renewalPolicy->getRenewalExpiration());

        $this->assertTrue($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-31 23:59')));
        $this->assertFalse($renewalPolicy->isRenewalAllowed(false, new \DateTime('2017-01-01')));

        $renewalPolicy->unrenew(new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_UNRENEWED, $renewalPolicy->getStatus());

        $renewalPolicy->activate(new \DateTime('2017-01-01'));
    }

    /**
     * @expectedException \Exception
     */
    public function testRenewInvalidStatus()
    {
        $policy = $this->getPolicy(static::generateEmail('testRenewInvalidStatus', $this));
        $renewalPolicy = $this->getRenewalPolicy($policy);
        $renewalPolicy->setStatus(Policy::STATUS_ACTIVE);
        $this->assertFalse($renewalPolicy->isRenewalAllowed(false, new \DateTime('2016-12-15')));
        $renewalPolicy->renew(0);
    }

    /**
     * @expectedException \Exception
     */
    public function testActivateInvalidStatus()
    {
        $policy = $this->getPolicy(static::generateEmail('testActivateInvalidStatus', $this));
        $renewalPolicy = $this->getRenewalPolicy($policy);
        $renewalPolicy->renew(0);
        $renewalPolicy->setStatus(Policy::STATUS_ACTIVE);
        $renewalPolicy->activate(new \DateTime('2017-01-01'));
    }

    /**
     * @expectedException \Exception
     */
    public function testActivateTooEarly()
    {
        $policy = $this->getPolicy(static::generateEmail('testActivateInvalidStatus', $this));
        $renewalPolicy = $this->getRenewalPolicy($policy);
        $renewalPolicy->renew(0);
        $renewalPolicy->activate(new \DateTime('2016-12-31 23:59'));
    }

    /**
     * @expectedException \Exception
     */
    public function testActivateTooLate()
    {
        $policy = $this->getPolicy(static::generateEmail('testActivateInvalidStatus', $this));
        $renewalPolicy = $this->getRenewalPolicy($policy);
        $renewalPolicy->renew(0);
        $renewalPolicy->activate(new \DateTime('2017-01-09'));
    }

    /**
     * @expectedException \Exception
     */
    public function testExpireTooEarly()
    {
        $policy = $this->getPolicy(static::generateEmail('testExpireTooEarly', $this));
        $policy->expire(new \DateTime("2016-12-31 23:58"));
    }

    /**
     * @expectedException \Exception
     */
    public function testFullyExpireTooEarly()
    {
        $policy = $this->getPolicy(static::generateEmail('testExpireTooEarly', $this));
        $policy->fullyExpire(new \DateTime("2016-12-31 23:58"));
    }

    /**
     * @expectedException \Exception
     */
    public function testExpireInvalidStatus()
    {
        $policy = $this->getPolicy(static::generateEmail('testExpireInvalidStatus', $this));
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->expire(new \DateTime("2017-01-01"));
    }

    /**
     * @expectedException \Exception
     */
    public function testFullyExpireInvalidStatus()
    {
        $policy = $this->getPolicy(static::generateEmail('testExpireInvalidStatus', $this));
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->fullyExpire(new \DateTime("2017-01-01"));
    }

    public function testExpireUnpaid()
    {
        $policy = $this->getPolicy(static::generateEmail('testExpireInvalidStatus', $this));
        $policy->setStatus(Policy::STATUS_UNPAID);
        $policy->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policy->getStatus());
    }

    public function testExpireWithPromoNoCashback()
    {
        $policyA = $this->getPolicy(static::generateEmail('testExpireA', $this));
        $policyB = $this->getPolicy(static::generateEmail('testExpireB', $this));
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        $this->createLinkedConnections($policyA, $policyB, 15, 15);
        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB->getUser());
        static::$dm->persist($policyA);
        static::$dm->persist($policyB);
        static::$dm->flush();
        $this->assertNotNull($policyA->getId());
        $this->assertNotNull($policyB->getId());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = self::$dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicyA */
        $updatedPolicyA = $repo->find($policyA->getId());

        $foundSoSure = false;
        $foundPot = false;
        foreach ($updatedPolicyA->getAllPayments() as $payment) {
            if ($payment instanceof SoSurePotRewardPayment) {
                $this->assertTrue($this->areEqualToTwoDp(-5, $payment->getAmount()));
                // expected only once
                $this->assertFalse($foundSoSure);
                $foundSoSure = true;
            }
            if ($payment instanceof PotRewardPayment) {
                $this->assertTrue($this->areEqualToTwoDp(-10, $payment->getAmount()));
                // expected only once
                $this->assertFalse($foundPot);
                $foundPot = true;
            }
        }
        $this->assertTrue($foundSoSure);
        $this->assertTrue($foundPot);
        $this->assertNotNull($updatedPolicyA->getCashback());
        if ($updatedPolicyA->getCashback()) {
            $this->assertEquals(15, $updatedPolicyA->getCashback()->getAmount());
            $this->assertEquals(Cashback::STATUS_MISSING, $updatedPolicyA->getCashback()->getStatus());
        }
    }

    public function testExpireWithPromoNoCashbackClaimed()
    {
        $policyA = $this->getPolicy(static::generateEmail('testExpireWithPromoNoCashbackClaimedA', $this));
        $policyB = $this->getPolicy(static::generateEmail('testExpireWithPromoNoCashbackClaimedB', $this));
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        $this->createLinkedConnections($policyA, $policyB, 15, 15);
        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB->getUser());
        static::$dm->persist($policyA);
        static::$dm->persist($policyB);
        static::$dm->flush();
        $this->assertNotNull($policyA->getId());
        $this->assertNotNull($policyB->getId());

        $claimA = new Claim();
        $claimA->setType(Claim::TYPE_LOSS);
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $claimA->setProcessed(true);
        $policyA->addClaim($claimA);

        $policyA->fullyExpire(new \DateTime("2017-01-29"));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = self::$dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicyA */
        $updatedPolicyA = $repo->find($policyA->getId());

        $foundSoSure = false;
        $foundSoSureRefund = false;
        $foundPot = false;
        $foundPotRefund = false;
        foreach ($updatedPolicyA->getAllPayments() as $payment) {
            if ($payment instanceof SoSurePotRewardPayment && $payment->getAmount() == -5) {
                // expected only once
                $this->assertFalse($foundSoSure);
                $foundSoSure = true;
            }
            if ($payment instanceof SoSurePotRewardPayment && $payment->getAmount() == 5) {
                // expected only once
                $this->assertFalse($foundSoSureRefund);
                $foundSoSureRefund = true;
            }
            if ($payment instanceof PotRewardPayment && $payment->getAmount() == -10) {
                // expected only once
                $this->assertFalse($foundPot);
                $foundPot = true;
            }
            if ($payment instanceof PotRewardPayment && $payment->getAmount() == 10) {
                // expected only once
                $this->assertFalse($foundPotRefund);
                $foundPotRefund = true;
            }
        }
        $this->assertTrue($foundSoSure);
        $this->assertTrue($foundSoSureRefund);
        $this->assertTrue($foundPot);
        $this->assertTrue($foundPotRefund);
        $this->assertNotNull($updatedPolicyA->getCashback());
        if ($updatedPolicyA->getCashback()) {
            $this->assertEquals(0, $updatedPolicyA->getCashback()->getAmount());
            $this->assertEquals(Cashback::STATUS_MISSING, $updatedPolicyA->getCashback()->getStatus());
        }
    }

    public function testExpireNoPromoWithCashback()
    {
        $policyA = $this->getPolicy(static::generateEmail('testExpireWithCashbackA', $this));
        $policyB = $this->getPolicy(static::generateEmail('testExpireWithCashbackB', $this));
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $cashback = new Cashback();
        $cashback->setAccountName('a b');
        $cashback->setSortCode('123456');
        $cashback->setAccountNumber('12345678');
        $policyA->setCashback($cashback);

        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB->getUser());
        static::$dm->persist($policyA);
        static::$dm->persist($policyB);
        static::$dm->flush();
        $this->assertNotNull($policyA->getId());
        $this->assertNotNull($policyB->getId());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = self::$dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicyA */
        $updatedPolicyA = $repo->find($policyA->getId());

        $foundSoSure = false;
        $foundPot = false;
        foreach ($updatedPolicyA->getAllPayments() as $payment) {
            if ($payment instanceof SoSurePotRewardPayment) {
                // expected only once
                $this->assertFalse($foundSoSure);
                $foundSoSure = true;
            }
            if ($payment instanceof PotRewardPayment) {
                $this->assertTrue($this->areEqualToTwoDp(-10, $payment->getAmount()));
                // expected only once
                $this->assertFalse($foundPot);
                $foundPot = true;
            }
        }
        $this->assertFalse($foundSoSure);
        $this->assertTrue($foundPot);
        $this->assertNotNull($updatedPolicyA->getCashback());
        if ($updatedPolicyA->getCashback()) {
            $this->assertEquals(10, $updatedPolicyA->getCashback()->getAmount());
            $this->assertEquals(Cashback::STATUS_PENDING_CLAIMABLE, $updatedPolicyA->getCashback()->getStatus());
        }
    }

    public function testRenewalIpt()
    {
        $policy = $this->getPolicy(static::generateEmail('testRenewalIpt', $this), new \DateTime('2016-06-02'));
        $this->assertEquals(0.095, $policy->getPremium()->getIptRate());

        $renewalPolicy = $policy->createPendingRenewal($policy->getPolicyTerms(), new \DateTime('2017-05-15'));
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());
        $this->assertEquals(0.12, $renewalPolicy->getPremium()->getIptRate());
        $this->assertEquals(0.095, $policy->getPremium()->getIptRate());

        // in policy service, renew calls create
        $renewalPolicy->create(rand(1, 999999), null, new \DateTime('2017-06-02'));
        $renewalPolicy->renew(0, false, new \DateTime('2017-05-30'));
        $this->assertEquals(0.12, $renewalPolicy->getPremium()->getIptRate());
        $this->assertEquals(0.095, $policy->getPremium()->getIptRate());

        $policy->expire(new \DateTime('2017-06-02'));

        $renewalPolicy->activate(new \DateTime('2017-06-02'));
        $this->assertEquals(0.12, $renewalPolicy->getPremium()->getIptRate());
        $this->assertEquals(0.095, $policy->getPremium()->getIptRate());
    }

    public function testAdjustedRewardPotPaymentAmount()
    {
        $policy = $this->getPolicy(static::generateEmail('testAdjustedRewardPotPaymentAmount ', $this));
        $this->assertEquals(0, $policy->getAdjustedRewardPotPaymentAmount());

        $potReward = new PotRewardPayment();
        $potReward->setAmount(2);
        $policy->addPayment($potReward);
        $this->assertEquals(2, $policy->getAdjustedRewardPotPaymentAmount());

        $potReward = new SoSurePotRewardPayment();
        $potReward->setAmount(1);
        $policy->addPayment($potReward);
        $this->assertEquals(3, $policy->getAdjustedRewardPotPaymentAmount());

        $potReward = new PotRewardPayment();
        $potReward->setAmount(-2);
        $policy->addPayment($potReward);
        $this->assertEquals(1, $policy->getAdjustedRewardPotPaymentAmount());
    }

    public function testExpireRenewed()
    {
        $policyA = $this->getPolicy(static::generateEmail('testExpireRenewedA', $this));
        $policyB = $this->getPolicy(static::generateEmail('testExpireRenewedB', $this));
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $renewalPolicy = $policyA->createPendingRenewal($policyA->getPolicyTerms(), new \DateTime('2016-12-15'));
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());
        //\Doctrine\Common\Util\Debug::dump($policyA);
        $renewalPolicy->renew(10, false, new \DateTime('2016-12-16'));

        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB->getUser());
        static::$dm->persist($policyA);
        static::$dm->persist($policyB);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();
        $this->assertNotNull($policyA->getId());
        $this->assertNotNull($policyB->getId());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = self::$dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicyA */
        $updatedPolicyA = $repo->find($policyA->getId());

        $foundSoSure = false;
        $foundPot = false;
        $foundDiscount = false;
        foreach ($updatedPolicyA->getAllPayments() as $payment) {
            if ($payment instanceof SoSurePotRewardPayment) {
                $foundSoSure = true;
            }
            if ($payment instanceof PotRewardPayment) {
                $this->assertTrue($this->areEqualToTwoDp(-10, $payment->getAmount()));
                $foundPot = true;
            }
        }
        $this->assertFalse($foundSoSure);
        $this->assertTrue($foundPot);
        $this->assertNull($updatedPolicyA->getCashback());
    }

    public function testExpireAutoRenewed()
    {
        $policyA = $this->getPolicy(static::generateEmail('testExpireAutoRenewedA', $this));
        $policyB = $this->getPolicy(static::generateEmail('testExpireAutoRenewedB', $this));
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $renewalPolicy = $policyA->createPendingRenewal($policyA->getPolicyTerms(), new \DateTime('2016-12-15'));
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());
        //\Doctrine\Common\Util\Debug::dump($policyA);

        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $renewalPolicy->renew(10, true, new \DateTime('2017-01-01'));
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB->getUser());
        static::$dm->persist($policyA);
        static::$dm->persist($policyB);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();
        $this->assertNotNull($policyA->getId());
        $this->assertNotNull($policyB->getId());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = self::$dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicyA */
        $updatedPolicyA = $repo->find($policyA->getId());

        $foundSoSure = false;
        $foundPot = false;
        $foundDiscount = false;
        foreach ($updatedPolicyA->getAllPayments() as $payment) {
            if ($payment instanceof SoSurePotRewardPayment) {
                $foundSoSure = true;
            }
            if ($payment instanceof PotRewardPayment) {
                $this->assertTrue($this->areEqualToTwoDp(-10, $payment->getAmount()));
                $foundPot = true;
            }
        }
        $this->assertFalse($foundSoSure);
        $this->assertTrue($foundPot);
        $this->assertNull($updatedPolicyA->getCashback());
    }

    public function testCorrectFiguresAfterPotReward()
    {
        $policyA = $this->getPolicy(
            static::generateEmail('testCorrectFiguresAfterPotRewardA', $this),
            null,
            static::getRandomPhone(static::$dm)
        );
        $policyB = $this->getPolicy(
            static::generateEmail('testCorrectFiguresAfterPotRewardB', $this),
            null,
            static::getRandomPhone(static::$dm)
        );
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyA->setPremiumInstallments(12);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $paymentA = new JudoPayment();
        $paymentA->setAmount($policyA->getPremium()->getYearlyPremiumPrice());
        $paymentA->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $paymentA->setResult(JudoPayment::RESULT_SUCCESS);
        $paymentA->setReceipt(rand(1, 999999));
        $paymentA->setDate(new \DateTime('2016-01-01'));
        $policyA->addPayment($paymentA);

        $renewalPolicy = $policyA->createPendingRenewal($policyA->getPolicyTerms(), new \DateTime('2016-12-15'));
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());
        //\Doctrine\Common\Util\Debug::dump($policyA);
        $renewalPolicy->renew(10, false, new \DateTime('2016-12-16'));

        $policyA->expire(new \DateTime("2017-01-01"));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());
        static::$dm->persist($policyA->getUser());
        static::$dm->persist($policyB->getUser());
        static::$dm->persist($policyA);
        static::$dm->persist($policyB);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();
        $this->assertNotNull($policyA->getId());
        $this->assertNotNull($policyB->getId());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = self::$dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicyA */
        $updatedPolicyA = $repo->find($policyA->getId());

        $foundSoSure = false;
        $foundPot = false;
        $foundDiscount = false;
        foreach ($updatedPolicyA->getAllPayments() as $payment) {
            if ($payment instanceof SoSurePotRewardPayment) {
                $foundSoSure = true;
            }
            if ($payment instanceof PotRewardPayment) {
                $this->assertTrue($this->areEqualToTwoDp(-10, $payment->getAmount()));
                $foundPot = true;
            }
        }
        $this->assertFalse($foundSoSure);
        $this->assertTrue($foundPot);
        $this->assertNull($updatedPolicyA->getCashback());
        $this->assertNull($policyA->arePolicyScheduledPaymentsCorrect());
        $this->assertTrue($policyA->hasCorrectCommissionPayments(new \DateTime('2017-01-01')));
    }

    public function testHasCorrectCommissionPaymentsUnpaid()
    {
        $date = new \DateTime('2018-03-19');
        $policy = $this->getPolicy(
            static::generateEmail('testHasCorrectCommissionPaymentsUnpaid', $this),
            $date,
            self::getRandomPhone(static::$dm)
        );
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(rand(1, 999999));
        $policy->setPremiumInstallments(12);
        for ($i = 0; $i < 8; $i++) {
            $month = clone $date;
            $month = $month->add(new \DateInterval(sprintf('P%dM', $i)));
            $payment = self::addPayment(
                $policy,
                $policy->getPremium()->getMonthlyPremiumPrice(),
                Salva::MONTHLY_TOTAL_COMMISSION,
                null,
                $month
            );
        }
        $policy->cancel(Policy::CANCELLED_UPGRADE, new \DateTime('2018-12-17'));

        $validationDate = new \DateTime('2018-12-27');
        $totalPayments = $policy->getTotalSuccessfulStandardPayments(false, $validationDate);
        $numPayments = $policy->getPremium()->getNumberOfMonthlyPayments($totalPayments);
        $this->assertEquals(8, $numPayments);


        $this->assertEquals(7.12, $policy->getExpectedCommission($validationDate));
        $this->assertTrue($policy->hasCorrectCommissionPayments($validationDate));
    }

    public function testHasCorrectCommissionPaymentsCancelledRefund()
    {
        $date = new \DateTime('2018-02-10');
        $policy = $this->getPolicy(
            static::generateEmail('testHasCorrectCommissionPaymentsCancelledRefund', $this),
            $date,
            self::getRandomPhone(static::$dm)
        );
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(rand(1, 999999));
        $policy->setPremiumInstallments(12);
        for ($i = 0; $i < 2; $i++) {
            $month = clone $date;
            $month = $month->add(new \DateInterval(sprintf('P%dM', $i)));
            $payment = self::addPayment(
                $policy,
                $policy->getPremium()->getMonthlyPremiumPrice(),
                Salva::MONTHLY_TOTAL_COMMISSION,
                null,
                $month
            );
        }
        $payment = self::addPayment(
            $policy,
            -4.32,
            -0.40,
            null,
            new \DateTime('2018-03-28')
        );
        $policy->cancel(Policy::CANCELLED_UPGRADE, new \DateTime('2018-03-28'));

        $validationDate = new \DateTime('2018-12-27');
        $totalPayments = $policy->getTotalSuccessfulStandardPayments(false, $validationDate);
        $numPayments = $policy->getPremium()->getNumberOfMonthlyPayments($totalPayments);
        $this->assertEquals(null, $numPayments);


        $this->assertEquals(1.38, $policy->getExpectedCommission($validationDate));
        $this->assertTrue($policy->hasCorrectCommissionPayments($validationDate));
    }

    public function testRenewTooMany()
    {
        $policy = $this->getPolicy(static::generateEmail('testRenewTooMany', $this));
        $policy->setStatus(Policy::STATUS_ACTIVE);
        for ($i = 1; $i <= $policy->getMaxConnectionsLimit() + 3; $i++) {
            $policyConnect = $this->getPolicy(static::generateEmail(sprintf('policyConnect%d', $i), $this));
            $policyConnect->setStatus(Policy::STATUS_ACTIVE);
            $this->createLinkedConnections($policy, $policyConnect, 2, 2);
        }

        $renewalPolicy = $policy->createPendingRenewal($policy->getPolicyTerms(), new \DateTime('2016-12-15'));
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());
        //\Doctrine\Common\Util\Debug::dump($renewalPolicy);
        $renewalPolicy->renew(0, false, new \DateTime('2016-12-16'));
        $renewed = 0;
        $unrenewed = 0;
        //\Doctrine\Common\Util\Debug::dump($renewalPolicy->getRenewalConnections());
        foreach ($renewalPolicy->getRenewalConnections() as $connection) {
            if ($connection->getRenew()) {
                $renewed++;
            } else {
                $unrenewed++;
            }
        }
        $this->assertEquals(10, $renewalPolicy->getConnectionValue(new \DateTime('2016-12-15')));
        $this->assertLessThan(10, $renewalPolicy->getMaxConnections(new \DateTime('2016-12-15')));
        $this->assertEquals($renewed, $renewalPolicy->getMaxConnections(new \DateTime('2016-12-15')));
        $this->assertGreaterThan(2, $unrenewed);
        $this->assertLessThan(10, $renewed);
    }

    public function testRenewPrevious()
    {
        $policyA1 = $this->getPolicy(
            static::generateEmail('testRenewPrevious', $this, true),
            new \DateTime('2017-02-01')
        );
        $policyA1->setStatus(Policy::STATUS_ACTIVE);

        $policyB1 = $this->getPolicy(
            static::generateEmail('testRenewPrevious', $this, true),
            new \DateTime('2017-06-01')
        );
        $policyB1->setStatus(Policy::STATUS_ACTIVE);

        $this->createLinkedConnections(
            $policyA1,
            $policyB1,
            2,
            2,
            new \DateTime('2017-06-01'),
            new \DateTime('2017-06-01')
        );

        $renewalPolicyA1 = $policyA1->createPendingRenewal(
            $policyA1->getPolicyTerms(),
            new \DateTime('2018-01-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA1->getStatus());
        //\Doctrine\Common\Util\Debug::dump($renewalPolicy);
        $renewalPolicyA1->renew(0, true, new \DateTime('2018-02-01'));
        $renewed = 0;
        $unrenewed = 0;
        //\Doctrine\Common\Util\Debug::dump($renewalPolicy->getRenewalConnections());
        foreach ($renewalPolicyA1->getRenewalConnections() as $connection) {
            if ($connection->getRenew()) {
                $renewed++;
            } else {
                $unrenewed++;
            }
        }
        $this->assertEquals(0, $unrenewed);
        $this->assertEquals(1, $renewed);

        $renewalPolicyB1 = $policyB1->createPendingRenewal(
            $policyB1->getPolicyTerms(),
            new \DateTime('2018-05-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB1->getStatus());
        //\Doctrine\Common\Util\Debug::dump($renewalPolicy);
        $renewalPolicyB1->renew(0, true, new \DateTime('2018-06-01'));
        $renewed = 0;
        $unrenewed = 0;
        //\Doctrine\Common\Util\Debug::dump($renewalPolicy->getRenewalConnections());
        foreach ($renewalPolicyB1->getRenewalConnections() as $connection) {
            if ($connection->getRenew()) {
                $renewed++;
            } else {
                $unrenewed++;
            }
        }
        $this->assertEquals(0, $unrenewed);
        $this->assertEquals(1, $renewed);
    }

    public function testGetUnpaidReasonInvalidPolicy()
    {
        $policy = $this->getPolicy(static::generateEmail('testGetUnpaidReason', $this));
        $this->assertNull($policy->getUnpaidReason());
    }

    public function testGetUnpaidReasonJudo()
    {
        $date = new \DateTime('2016-01-28 15:00');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12,
            $date
        );
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-01-31 00:01')));
        $policy->setStatus(Policy::STATUS_UNPAID);

        $this->assertEquals(Policy::UNPAID_PAID, $policy->getUnpaidReason(new \DateTime('2016-01-01')));
        $this->assertEquals(
            Policy::UNPAID_PAYMENT_METHOD_MISSING,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );

        self::setPaymentMethodForPolicy($policy, '0116');
        $this->assertEquals(
            Policy::UNPAID_CARD_EXPIRED,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );

        self::setPaymentMethodForPolicy($policy, '0120');
        //\Doctrine\Common\Util\Debug::dump($policy->getLastPaymentCredit(), 3);
        $this->assertEquals(
            Policy::UNPAID_CARD_PAYMENT_MISSING,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );

        // add an ontime failed payment
        $payment = self::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-02-28 15:00'),
            JudoPayment::RESULT_DECLINED
        );
        $this->assertEquals(
            Policy::UNPAID_CARD_PAYMENT_FAILED,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );
    }

    public function testGetUnpaidReasonBacs()
    {
        $date = new \DateTime('2016-01-28 15:00');
        $policy = $this->createPolicyForCancellation(
            static::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date),
            Salva::MONTHLY_TOTAL_COMMISSION,
            12,
            $date
        );
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-01-31 00:01')));
        $policy->setStatus(Policy::STATUS_UNPAID);

        $this->assertEquals(Policy::UNPAID_PAID, $policy->getUnpaidReason(new \DateTime('2016-01-01')));
        $this->assertEquals(
            Policy::UNPAID_PAYMENT_METHOD_MISSING,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );

        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_PENDING_INIT);
        $this->assertEquals(
            Policy::UNPAID_BACS_MANDATE_PENDING,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );
        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_PENDING_APPROVAL);
        $this->assertEquals(
            Policy::UNPAID_BACS_MANDATE_PENDING,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );

        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_CANCELLED);
        $this->assertEquals(
            Policy::UNPAID_BACS_MANDATE_INVALID,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );

        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_FAILURE);
        $this->assertEquals(
            Policy::UNPAID_BACS_MANDATE_INVALID,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );

        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_SUCCESS);

        $this->assertEquals(
            Policy::UNPAID_BACS_PAYMENT_MISSING,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );

        $scheduledPayment = self::$bacsService->scheduleBacsPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            ScheduledPayment::TYPE_USER_WEB,
            ''
        );
        $this->assertEquals(
            Policy::UNPAID_BACS_PAYMENT_PENDING,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );

        // add an ontime payment
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_PENDING);
        $payment = self::addBacsPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            new \DateTime('2016-02-28 15:00'),
            true,
            BacsPayment::STATUS_GENERATED
        );
        $this->assertEquals(
            Policy::UNPAID_BACS_PAYMENT_PENDING,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );
        $payment->setStatus(BacsPayment::STATUS_PENDING);
        $this->assertEquals(
            Policy::UNPAID_BACS_PAYMENT_PENDING,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );

        $payment->setStatus(BacsPayment::STATUS_FAILURE);
        $this->assertEquals(
            Policy::UNPAID_BACS_PAYMENT_FAILED,
            $policy->getUnpaidReason(new \DateTime('2016-03-01'))
        );
    }

    private function getPolicy($email, \DateTime $date = null, $phone = null)
    {
        if (!$date) {
            $date = new \DateTime("2016-01-01");
        }
        $policy = new SalvaPhonePolicy();

        $user = new User();
        $user->setEmail($email);
        $user->setEnabled(true);
        self::addAddress($user);

        if (!$phone) {
            $policy->setPhone(static::$phone);
        } else {
            $policy->setPhone($phone, $date);
        }

        $policy->init($user, static::getLatestPolicyTerms(self::$dm));

        $issueDate = \DateTime::createFromFormat('U', time());
        $policy->create(rand(1, 999999), null, $date, rand(1, 9999));
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $issueDate2 = clone $issueDate;
        $issueDate2->add(new \DateInterval('PT1S'));

        $this->assertEquals($date, $policy->getStart(), '', 1);
        $this->assertTrue($policy->getIssueDate() == $issueDate || $policy->getIssueDate() == $issueDate2);

        return $policy;
    }

    public function testIsRepurchase()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('testIsRepurchase', $this));
        self::$dm->persist($user);
        self::addAddress($user);
        $policyA = new SalvaPhonePolicy();
        $policyA->init($user, self::getLatestPolicyTerms(static::$dm));
        $policyA->setPhone(self::$phone);
        $policyA->create(rand(1, 999999), null, null, rand(1, 9999));
        $policyA->setImei(rand(1, 999999));
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyA->setId(rand(1, 999999));
        $this->assertFalse($policyA->isRepurchase());

        $policyB = new SalvaPhonePolicy();
        $policyB->init($user, self::getLatestPolicyTerms(static::$dm));
        $policyB->setPhone(self::$phone);
        $policyB->create(rand(1, 999999), null, null, rand(1, 9999));
        $this->assertFalse($policyB->isSameInsurable($policyA));
        $policyB->setImei($policyA->getImei());
        $policyB->setStatus(null);
        $policyB->setId(rand(1, 999999));
        $this->assertTrue($policyB->isSameInsurable($policyA));
        $this->assertTrue($policyB->isRepurchase());
    }

    public function testHasManualBacsPayment()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('testHasManualBacsPayment', $this));
        self::$dm->persist($user);
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->init($user, self::getLatestPolicyTerms(static::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setImei(rand(1, 999999));
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(rand(1, 999999));

        $this->assertFalse($policy->hasManualBacsPayment());
        $bacsPayment = new BacsPayment();
        $bacsPayment->setManual(true);
        $policy->addPayment($bacsPayment);
        $this->assertTrue($policy->hasManualBacsPayment());
    }

    public function testIsUnpaidCloseToExpirationDate()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('testIsUnpaidCloseToExpirationDate', $this));
        self::$dm->persist($user);
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->init($user, self::getLatestPolicyTerms(static::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), null, null, rand(1, 9999));
        $policy->setImei(rand(1, 999999));
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(rand(1, 999999));
        $policy->setPremiumInstallments(12);

        $expiration = $policy->getPolicyExpirationDate();
        $expirationTwelve = clone $expiration;
        $expirationTwelve = $expirationTwelve->sub(new \DateInterval('P12D'));
        $expirationTen = clone $expiration;
        $expirationTen = $expirationTen->sub(new \DateInterval('P10D'));

        $this->assertNull($policy->isUnpaidCloseToExpirationDate($expirationTwelve));
        $policy->setStatus(Policy::STATUS_UNPAID);
        $this->assertFalse($policy->isUnpaidCloseToExpirationDate($expirationTwelve));
        $this->assertTrue($policy->isUnpaidCloseToExpirationDate($expirationTen));
    }

    public function testIsUnpaidPastExpiration()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('testIsUnpaidPastExpiration', $this));
        self::$dm->persist($user);
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->init($user, self::getLatestPolicyTerms(static::$dm));
        $policy->setPhone(self::$phone);
        $policy->create(rand(1, 999999), 'Mob', null, rand(1, 9999));
        $policy->setImei(rand(1, 999999));
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setId(rand(1, 999999));
        $policy->setPremiumInstallments(12);

        $expiration = $policy->getPolicyExpirationDate();
        $diff = $expiration->diff($policy->getEnd());
        //print_r($diff);
        // normally 334, but if current date is 29th, then 335 (likewise, 30th => 336, 31st => 337)
        $this->assertTrue(in_array($diff->days, [334, 335, 336, 337]));

        $month = clone $policy->getStart();
        for ($i = 0; $i <= 10; $i++) {
            $month = clone $policy->getStart();
            $month = $month->add(new \DateInterval(sprintf('P%dM', $i)));

            /*
            print $policy->getNextBillingDate($month)->format(\DateTime::ATOM) . PHP_EOL;
            print $policy->getPolicyExpirationDate($month)->format(\DateTime::ATOM) . PHP_EOL;
            */

            // add an ontime payment
            $payment = self::addBacsPayment(
                $policy,
                $policy->getPremium()->getMonthlyPremiumPrice(),
                Salva::MONTHLY_TOTAL_COMMISSION,
                $month
            );
        }

        /*
        print $policy->getPremium()->getMonthlyPremiumPrice() * 12 . PHP_EOL;
        print $policy->getPremiumPaid();
        */

        $policy->setStatus(Policy::STATUS_UNPAID);
        $expiration = $policy->getPolicyExpirationDate($month);
        $diff = $expiration->diff($policy->getEnd());

        // print_r($diff);
        // normally 0, but if current date is 29th, then 1 (likewise, 30th => 2, 31st => 3)
        $this->assertTrue(in_array($diff->days, [0, 1, 2, 3]));
        $this->assertTrue($expiration < $policy->getEnd());
    }

    public function testSetPolicyStatusActiveIfUnpaid()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPolicyStatusActiveIfUnpaid();
        $this->assertNull($policy->getStatus());

        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPolicyStatusActiveIfUnpaid();
        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $policy->setStatus(Policy::STATUS_UNPAID);
        $policy->setPolicyStatusActiveIfUnpaid();
        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setPolicyStatusActiveIfUnpaid();
        $this->assertEquals(Policy::STATUS_CANCELLED, $policy->getStatus());
    }

    public function testSetPolicyStatusUnpaidIfActive()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setPolicyStatusUnpaidIfActive();
        $this->assertNull($policy->getStatus());

        $policy->setStatus(Policy::STATUS_UNPAID);
        $policy->setPolicyStatusUnpaidIfActive();
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());

        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPolicyStatusUnpaidIfActive(false);
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());

        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPolicyStatusUnpaidIfActive(true);
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());
    }

    public function testSetPolicyStatusUnpaidIfActiveBacs()
    {
        // TODO: see why this fails if on the 29th
        $now = new \DateTime('2018-11-28');
        $date = clone $now;
        $date = $date->sub(new \DateInterval('P2M'));
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSetPolicyStatusUnpaidIfActiveBacs', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, $date, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $date, true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertTrue($policy->isPolicyPaidToDate($date, true));
        $this->assertFalse($policy->isPolicyPaidToDate($now, true));

        $policy->setPolicyStatusUnpaidIfActive(true);
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());

        self::addBacsPayPayment($policy, $now);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();
        $this->assertTrue($policy->isPolicyPaidToDate($now, true));
        $this->assertTrue($policy->isPolicyPaidToDate($date));

        self::addBacsPayment(
            $policy,
            (0 - $policy->getPremium()->getMonthlyPremiumPrice()),
            (0 - Salva::MONTHLY_TOTAL_COMMISSION),
            $this->getNextBusinessDay($now),
            false
        );
        static::$dm->flush();

        $this->assertTrue($policy->isPolicyPaidToDate($now));
        $this->assertFalse($policy->isPolicyPaidToDate($now, true, false, true));
        $policy->setPolicyStatusUnpaidIfActive(true);
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());
    }
}
