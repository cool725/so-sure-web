<?php

namespace AppBundle\Tests\Listener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use AppBundle\Listener\UserListener;
use AppBundle\Listener\DoctrineUserListener;
use AppBundle\Listener\PushListener;

use AppBundle\Event\ClaimEvent;
use AppBundle\Event\LeadEvent;
use AppBundle\Event\UserEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\InvitationEvent;
use AppBundle\Event\PaymentEvent;
use AppBundle\Event\UserPaymentEvent;

use AppBundle\Document\Claim;
use AppBundle\Document\Lead;
use AppBundle\Document\User;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Invitation\EmailInvitation;

use AppBundle\Service\PushService;

/**
 * @group functional-net
 */
class PushListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $userRepo;
    protected static $pushService;

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
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$pushService = self::$container->get('app.push');
        self::$policyService = self::$container->get('app.policy');
    }

    public function tearDown()
    {
    }

    public function testPushPolicyActual()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPushPolicyActual', $this),
            'bar'
        );
        $user->setEnabled(true);
        $user->setLocked(false);

        $start = \DateTime::createFromFormat('U', time());
        $start = $start->sub(new \DateInterval('P360D'));

        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), $start, true);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);

        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $start);
        static::$policyService->setEnvironment('test');

        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $this->assertTrue($policy->isValidPolicy());
        $this->assertTrue($policy->getUser()->canRenewPolicy($policy));

        $now = \DateTime::createFromFormat('U', time());
        self::$policyService->createPendingRenewal($policy, $now);
    }

    public function testPushListener()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPushListener', $this),
            'bar'
        );
        $policy = new HelvetiaPhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        $listener = new PushListener(static::$pushService);
        $listener->onPolicyPendingRenewalEvent(new PolicyEvent($policy));

        // test is if the above generates an exception
        $this->assertTrue(true);
    }
}
