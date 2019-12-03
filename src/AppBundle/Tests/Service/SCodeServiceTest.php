<?php

namespace AppBundle\Tests\Service;

use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;

use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Address;
use AppBundle\Document\SCode;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\SCodeInvitation;
use AppBundle\Document\Invitation\FacebookInvitation;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;

use AppBundle\Service\InvitationService;
use AppBundle\Service\MailerService;

use AppBundle\Event\InvitationEvent;

use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\FullPotException;
use AppBundle\Exception\ClaimException;
use AppBundle\Exception\OptOutException;
use AppBundle\Exception\ConnectedInvitationException;
use AppBundle\Exception\DuplicateInvitationException;

/**
 * @group functional-nonet
 * @group fixed
 *
 * AppBundle\\Tests\\Service\\SCodeServiceTest
 */
class SCodeServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var UserRepository */
    protected static $userRepo;
    /** @var InvitationService */
    protected static $invitationService;
    protected static $phone2;
    protected static $scodeService;

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
        /** @var UserRepository $userRepo */
        $userRepo = self::$dm->getRepository(User::class);
        self::$userRepo = $userRepo;
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$scodeService = self::$container->get('app.scode');
        /** @var InvitationService invitationService */
        $invitationService = self::$container->get('app.invitation');
        $invitationService->setDebug(true);
        self::$invitationService = $invitationService;

        self::$policyService = self::$container->get('app.policy');

        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phone2 = $phoneRepo->findOneBy(['devices' => 'iPhone8,1', 'memory' => 64]);
    }

    public function setUp()
    {
        // reset environment
        self::$invitationService->setEnvironment('test');
        self::$policyService->setEnvironment('test');

        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phone2 = $phoneRepo->findOneBy(['devices' => 'iPhone8,1', 'memory' => 64]);
    }

    public function tearDown()
    {
        self::$dm->clear();
    }

    public function testGenerateUniqueSCode()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testGenerateUniqueSCode', $this),
            'bar'
        );
        $user->setFirstName('Aaa');
        $user->setLastName('Bbb');
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $scode = static::$scodeService->generateUniqueSCode($user, SCode::TYPE_MULTIPAY);
        $this->assertEquals(SCode::TYPE_MULTIPAY, $scode->getType());
        // policy create should generate 0001 entry
        $this->assertNotEquals('abbb0002', $scode->getCode());

        $scode = static::$scodeService->generateUniqueSCode($user, SCode::TYPE_STANDARD);
        $this->assertEquals(SCode::TYPE_STANDARD, $scode->getType());
        $this->assertEquals('abbb0002', $scode->getCode());
        $policy->addSCode($scode);
        static::$dm->flush();
        
        $scode = static::$scodeService->generateUniqueSCode($user, SCode::TYPE_STANDARD);
        $this->assertEquals(SCode::TYPE_STANDARD, $scode->getType());
        $this->assertEquals('abbb0003', $scode->getCode());
    }

    public function testGenerateUniqueSCodeMB()
    {
        $user = new User();
        $user->setFirstName("żbieta");
        $user->setLastName("Eżbieta");

        /** @var SCode $scode */
        $scode = static::$scodeService->generateUniqueSCode($user, SCode::TYPE_STANDARD);
        $this->assertEquals(SCode::TYPE_STANDARD, $scode->getType());
        $this->assertTrue(in_array($scode->getCode(), ['żeżb0001', 'żeżb0002', 'żeżb0003', 'żeżb0004']));
    }
}
