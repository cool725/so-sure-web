<?php

namespace AppBundle\Tests\Service;

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
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;

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
 *
 * AppBundle\\Tests\\Service\\SCodeServiceTest
 */
class SCodeServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $gocardless;
    protected static $dm;
    protected static $userRepo;
    protected static $userManager;
    protected static $invitationService;
    protected static $phone;
    protected static $phone2;
    protected static $policyService;
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
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$scodeService = self::$container->get('app.scode');
        self::$invitationService = self::$container->get('app.invitation');
        self::$invitationService->setDebug(true);

        self::$policyService = self::$container->get('app.policy');

        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phone2 = $phoneRepo->findOneBy(['devices' => 'iPhone8,1', 'memory' => 64]);
    }

    public function setUp()
    {
        // reset environment
        self::$invitationService->setEnvironment('test');
        self::$policyService->setEnvironment('test');

        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
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
}
