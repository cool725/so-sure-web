<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\Charge;
use AppBundle\Document\File\PicSureFile;
use AppBundle\Event\PicsureEvent;
use AppBundle\Listener\TrustpilotListener;
use AppBundle\Service\MailerService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use AppBundle\Listener\UserListener;
use AppBundle\Listener\DoctrineUserListener;
use AppBundle\Listener\IntercomListener;

use AppBundle\Event\ClaimEvent;
use AppBundle\Event\LeadEvent;
use AppBundle\Event\UserEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\InvitationEvent;
use AppBundle\Event\ConnectionEvent;
use AppBundle\Event\PaymentEvent;
use AppBundle\Event\UserPaymentEvent;

use AppBundle\Document\Claim;
use AppBundle\Document\Lead;
use AppBundle\Document\User;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Connection\StandardConnection;

use AppBundle\Service\IntercomService;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Listener\\TrustpilotListenerTest
 */
class TrustpilotListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $mailerService;

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
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$mailerService = self::$container->get('app.mailer');
    }

    public function tearDown()
    {
    }

    public function testTrustpilotPolicyCreated()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testTrustpilotPolicyCreated', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        $listener = new TrustpilotListener(static::$mailerService);
        $listener->onPolicyCreatedEvent(new PolicyEvent($policy));

        // good enough to execute method to ensure exception is not thrown
        $this->assertTrue(true);
    }
}
