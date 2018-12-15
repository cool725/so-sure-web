<?php

namespace AppBundle\Tests\Service;

use AppBundle\Repository\UserRepository;
use AppBundle\Service\MixpanelService;
use AppBundle\Service\RouterService;
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
use Symfony\Component\Templating\EngineInterface;

/**
 * Not a paid test, but unable to easily get to run on the build server (and often fails regardless)
 *
 * @group functional-paid
 *
 * AppBundle\\Tests\\Service\\InvitationServiceTest
 */
class InvitationServicePaidTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
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
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        /** @var UserRepository $userRepo */
        $userRepo = self::$dm->getRepository(User::class);
        self::$userRepo = $userRepo;
        self::$userManager = self::$container->get('fos_user.user_manager');
        $transport = new \Swift_Transport_NullTransport(new \Swift_Events_SimpleEventDispatcher);
        /** @var EngineInterface $templating */
        $templating = self::$container->get('templating');
        /** @var RouterService $router */
        $router = self::$container->get('app.router');
        /** @var MixpanelService $mixpanelService */
        $mixpanelService = self::$container->get('app.mixpanel');
        $mailer = new MailerService(
            new \Swift_Mailer($transport),
            $transport,
            $templating,
            $router,
            'foo@foo.com',
            'bar',
            $mixpanelService
        );
        /** @var InvitationService invitationService */
        $invitationService = self::$container->get('app.invitation');
        $invitationService->setMailer($mailer);
        $invitationService->setDebug(true);
        self::$invitationService = $invitationService;

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
