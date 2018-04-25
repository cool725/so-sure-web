<?php

namespace AppBundle\Tests\Service;

use AppBundle\Repository\UserRepository;
use FOS\UserBundle\Model\UserManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;

use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Address;
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
 * @group functional-net
 *
 * AppBundle\\Tests\\Service\\InvitationServiceTest
 */
class InvitationServiceNetTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $gocardless;
    /** @var UserRepository */
    protected static $userRepo;
    protected static $invitationService;
    protected static $phone2;

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
        $transport = new \Swift_Transport_NullTransport(new \Swift_Events_SimpleEventDispatcher);
        $mailer = new MailerService(
            \Swift_Mailer::newInstance($transport),
            $transport,
            self::$container->get('templating'),
            self::$container->get('app.router'),
            'foo@foo.com',
            'bar'
        );
        /** @var InvitationService invitationService */
        self::$invitationService = self::$container->get('app.invitation');
        self::$invitationService->setMailer($mailer);
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

    public function testResolveSCode()
    {
        $this->assertEquals('a', static::$invitationService->resolveSCode('a'));
        $this->assertEquals('abcdefgh', static::$invitationService->resolveSCode('abcdefgh'));

        $this->assertEquals(
            'abcdefgh',
            static::$invitationService->resolveSCode('https://goo.gl/wSZGbc'),
            'If error, try running webvagrant, as requires server running on localhost.'
        );
        $this->assertEquals(
            'abcdefgh',
            static::$invitationService->resolveSCode('goo.gl/wSZGbc'),
            'If error, try clearing cache.'
        );

        $this->assertEquals('abcdefgh', static::$invitationService->resolveSCode(
            'https://sosure.test-app.link/GQnmCNBSwB'
        ));
        $this->assertEquals('abcdefgh', static::$invitationService->resolveSCode(
            'sosure.test-app.link/GQnmCNBSwB'
        ));
    }
}
