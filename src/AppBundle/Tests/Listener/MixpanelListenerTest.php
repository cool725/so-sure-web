<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\BankAccount;
use AppBundle\Document\Policy;
use AppBundle\Event\BacsEvent;
use AppBundle\Event\CardEvent;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use AppBundle\Listener\UserListener;
use AppBundle\Listener\DoctrineUserListener;
use AppBundle\Listener\MixpanelListener;

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
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Invitation\EmailInvitation;

use AppBundle\Service\MixpanelService;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Listener\\MixpanelListenerTest
 */
class MixpanelListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $userRepo;
    protected static $mixpanelService;
    protected static $requestService;
    protected static $redis;

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
        self::$mixpanelService = self::$container->get('app.mixpanel');
        self::$requestService = self::$container->get('app.request');
        self::$policyService = self::$container->get('app.policy');
        self::$redis = self::$container->get('snc_redis.default');
    }

    public function tearDown()
    {
    }

    public function testMixpanelPolicyActual()
    {
        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelPolicyActual', $this),
            'bar'
        );

        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);

        static::$requestService->setEnvironment('prod');
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        static::$requestService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());

        // person & track
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_PURCHASE_POLICY, $data['event']);
    }

    public function testMixpanelQueuePaymentSuccess()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelQueuePaymentSuccess', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        $payment = new JudoPayment();
        $payment->setId(1);
        $payment->setAmount(2);
        $payment->setPolicy($policy);
        $payment->setUser($user);

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $listener->onPaymentSuccessEvent(new PaymentEvent($payment));
        static::$requestService->setEnvironment('test');

        // person & track
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_PAYMENT, $data['event']);
    }

    public function testMixpanelQueueCancelled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelQueueCancelled', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));
        static::$requestService->setEnvironment('test');

        // Expect a user update + a policy cancel event
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_CANCEL_POLICY, $data['event']);
    }

    public function testMixpanelQueueCashback()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelQueueCashback', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $listener->onPolicyCashbackEvent(new PolicyEvent($policy));
        static::$requestService->setEnvironment('test');

        // Expect a user update + a policy cancel event
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_CASHBACK, $data['event']);
    }

    public function testMixpanelQueueRenew()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelQueueRenew', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $listener->onPolicyRenewedEvent(new PolicyEvent($policy));
        static::$requestService->setEnvironment('test');

        // Expect a user update + a policy cancel event
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_RENEW, $data['event']);
    }

    public function testMixpanelQueueDeclineRenew()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelQueueDeclineRenew', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $listener->onPolicyDeclineRenewedEvent(new PolicyEvent($policy));
        static::$requestService->setEnvironment('test');

        // Expect a user update + a policy cancel event
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_DECLINE_RENEW, $data['event']);
    }

    public function testMixpanelOnPolicyStatusEvent()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelOnPolicyStatusEvent', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));
        $policy->setStatus(Policy::STATUS_ACTIVE);

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $event = new PolicyEvent($policy);
        $event->setPreviousStatus(Policy::STATUS_UNPAID);
        $listener->onPolicyStatusEvent($event);
        static::$requestService->setEnvironment('test');

        // Expect a user update + a policy cancel event
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_POLICY_STATUS, $data['event']);
    }

    public function testMixpanelOnCardUpdatedEventUser()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelOnCardUpdatedEventUser', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));
        $policy->setStatus(Policy::STATUS_ACTIVE);

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $event = new CardEvent();
        $event->setUser($user);
        $listener->onCardUpdatedEvent($event);
        static::$requestService->setEnvironment('test');

        // Expect a user update + a policy cancel event
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_PAYMENT_METHOD_CHANGED, $data['event']);
    }

    public function testMixpanelOnCardUpdatedEventPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelOnCardUpdatedEventPolicy', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));
        $policy->setStatus(Policy::STATUS_ACTIVE);

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $event = new CardEvent();
        $event->setPolicy($policy);
        $listener->onCardUpdatedEvent($event);
        static::$requestService->setEnvironment('test');

        // Expect a user update + a policy cancel event
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_PAYMENT_METHOD_CHANGED, $data['event']);
    }

    public function testMixpanelOnBacsUpdatedEventUser()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelOnBacsUpdatedEventUser', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));
        $policy->setStatus(Policy::STATUS_ACTIVE);

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $event = new BacsEvent(new BankAccount());
        $event->setUser($user);
        $listener->onBacsUpdatedEvent($event);
        static::$requestService->setEnvironment('test');

        // Expect a user update + a policy cancel event
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_PAYMENT_METHOD_CHANGED, $data['event']);
    }

    public function testMixpanelOnBacsUpdatedEventPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelOnBacsUpdatedEventPolicy', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));
        $policy->setStatus(Policy::STATUS_ACTIVE);

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $event = new BacsEvent(new BankAccount());
        $event->setPolicy($policy);
        $listener->onBacsUpdatedEvent($event);
        static::$requestService->setEnvironment('test');

        // Expect a user update + a policy cancel event
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_PAYMENT_METHOD_CHANGED, $data['event']);
    }

    public function testMixpanelOnUserPaymentMethodChangedEvent()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelOnUserPaymentMethodChangedEvent', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));
        $policy->setStatus(Policy::STATUS_ACTIVE);

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $event = new UserEvent($user);
        $event->setPreviousPaymentMethod('judo');
        $listener->onUserPaymentMethodChangedEvent($event);
        static::$requestService->setEnvironment('test');

        // Expect a user update + a policy cancel event
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_PAYMENT_METHOD_CHANGED, $data['event']);
    }

    public function testMixpanelOnPolicyPaymentMethodChangedEvent()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testMixpanelOnPolicyPaymentMethodChangedEvent', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));
        $policy->setStatus(Policy::STATUS_ACTIVE);

        static::$redis->del(MixpanelService::KEY_MIXPANEL_QUEUE);
        $this->assertEquals(0, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));

        $listener = new MixpanelListener(static::$mixpanelService);
        static::$requestService->setEnvironment('prod');
        $event = new PolicyEvent($policy);
        $event->setPreviousPaymentMethod('judo');
        $listener->onPolicyPaymentMethodChangedEvent($event);
        static::$requestService->setEnvironment('test');

        // Expect a user update + a policy cancel event
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_PAYMENT_METHOD_CHANGED, $data['event']);
    }
}
