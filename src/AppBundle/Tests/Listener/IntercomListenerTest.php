<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\Charge;
use AppBundle\Document\File\PicSureFile;
use AppBundle\Event\PicsureEvent;
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
 * AppBundle\\Tests\\Listener\\IntercomListenerTest
 */
class IntercomListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $userRepo;
    protected static $intercomService;
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
        self::$intercomService = self::$container->get('app.intercom');
        self::$policyService = self::$container->get('app.policy');
        self::$redis = self::$container->get('snc_redis.default');
    }

    public function tearDown()
    {
    }

    public function testIntercomUserChargeActual()
    {
        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomUserChargeActual', $this),
            'bar'
        );
        // create + update
        $this->assertEquals(2, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);

        $charge = new Charge();
        $charge->setType(Charge::TYPE_GSMA);
        $charge->setUser($user);
        static::$dm->flush();

        $charge->setUser(null);
        static::$dm->flush();
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
    }

    public function testIntercomPolicyActual()
    {
        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('intercom-queue-actual', $this),
            'bar'
        );
        // create + update
        $this->assertEquals(2, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);

        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        //$data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        //$this->assertEquals($user->getId(), $data['userId']);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());

        // Expect a policy create event + a policy start event + user update
        $this->assertEquals(3, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2016-12-15')
        );

        // Expect a policy pending renewal event + user update
        $this->assertEquals(2, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        static::$policyService->renew($policy, 12, null, false, new \DateTime('2016-12-30'));

        // Expect a policy pending created event + user update
        $this->assertEquals(2, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);

        static::$policyService->expire($policy, new \DateTime('2017-01-01'));

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        static::$policyService->activate($renewalPolicy, new \DateTime('2017-01-01'));

        // Expect a policy start event + policy created event + 2 user updates
        $this->assertEquals(4, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }

    public function testIntercomQueuePolicyPot()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('intercom-queue-policy-pot', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPolicyPotEvent(new PolicyEvent($policy));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }

    public function testIntercomQueuePolicyUnpaid()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomQueuePolicyUnpaid', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPolicyUnpaidEvent(new PolicyEvent($policy));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }

    public function testIntercomQueuePolicyReactivated()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomQueuePolicyReactivated', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPolicyReactivatedEvent(new PolicyEvent($policy));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }

    public function testIntercomQueueCreated()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('intercom-queue-created', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPolicyCreatedEvent(new PolicyEvent($policy));

        // No events anymore - moved to started
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
    }

    public function testIntercomQueueCancelled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('intercom-queue-cancelled', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));

        // Expect a user update + a policy cancel event
        $this->assertEquals(2, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }

    public function testIntercomQueueLead()
    {
        $lead = new Lead();
        $lead->setEmail(static::generateEmail('intercom-queue-lead', $this));

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onLeadUpdatedEvent(new LeadEvent($lead));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($lead->getId(), $data['leadId']);
    }

    public function testIntercomQueueUser()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('intercom-queue-user', $this),
            'bar'
        );

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onUserUpdatedEvent(new UserEvent($user));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }

    public function testIntercomInvitations()
    {
        $userA = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomInvitationReceived-A', $this),
            'bar'
        );
        $policyA = new PhonePolicy();
        $policyA->setUser($userA);
        $policyA->setId(rand(1, 99999));

        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomInvitationReceived-B', $this),
            'bar'
        );
        $policyB = new PhonePolicy();
        $policyB->setUser($userB);
        $policyB->setId(rand(1, 99999));

        $invitation = new EmailInvitation();
        $invitation->setInviter($userA);
        $invitation->setInvitee($userB);
        $invitation->setEmail($userB->getEmailCanonical());

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $event = new InvitationEvent($invitation);
        $data = [
            'onInvitationAcceptedEvent' => 2,
        ];
        foreach ($data as $method => $count) {
            call_user_func([$listener, $method], $event);

            $this->assertEquals($count, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
            for ($i = 0; $i < $count; $i++) {
                $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
                if ($method == 'onInvitationAcceptedEvent') {
                    $this->assertTrue(isset($data['userId']));
                } else {
                    $this->assertEquals($invitation->getId(), $data['invitationId']);
                }
            }
        }
    }

    public function testIntercomConnection()
    {
        $userA = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomConnection-A', $this),
            'bar'
        );
        $policyA = new PhonePolicy();
        $policyA->setUser($userA);
        $policyA->setId(rand(1, 99999));

        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomConnection-B', $this),
            'bar'
        );
        $policyB = new PhonePolicy();
        $policyB->setUser($userB);
        $policyB->setId(rand(1, 99999));

        $connection = new StandardConnection();
        $connection->setSourcePolicy($policyA);
        $connection->setSourceUser($userA);
        $connection->setLinkedPolicy($policyB);
        $connection->setLinkedUser($userB);
        $connection->setId(rand(1, 99999));

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onConnectionConnectedEvent(new ConnectionEvent($connection));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($connection->getId(), $data['connectionId']);
    }

    public function testIntercomQueuePaymentSuccess()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomQueuePaymentSuccess', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        $payment = new JudoPayment();
        $payment->setId(1);
        $payment->setAmount(2);
        $payment->setPolicy($policy);

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPaymentSuccessEvent(new PaymentEvent($payment));

        $this->assertEquals(2, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $foundPayment = false;
        while ($data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE))) {
            if (isset($data['paymentId'])) {
                $this->assertEquals(1, $data['paymentId']);
                $foundPayment = true;
            }
        }
        $this->assertTrue($foundPayment);
    }

    public function testIntercomQueuePaymentFailed()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomQueuePaymentFailed', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        $payment = new JudoPayment();
        $payment->setId(2);
        $payment->setAmount(2);
        $payment->setPolicy($policy);

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPaymentFailedEvent(new PaymentEvent($payment));

        $this->assertEquals(2, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $foundPayment = false;
        while ($data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE))) {
            if (isset($data['paymentId'])) {
                $this->assertEquals(2, $data['paymentId']);
                $foundPayment = true;
            }
        }
        $this->assertTrue($foundPayment);
    }

    public function testIntercomQueuePaymentFirstProblem()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomQueuePaymentFirstProblem', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        $payment = new JudoPayment();
        $payment->setId(3);
        $payment->setAmount(3);
        $payment->setPolicy($policy);

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPaymentFirstProblemEvent(new PaymentEvent($payment));

        // User is also updated now
        $this->assertEquals(2, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $foundPayment = false;
        while ($data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE))) {
            if (isset($data['paymentId'])) {
                $this->assertEquals(3, $data['paymentId']);
                $foundPayment = true;
            }
        }
        $this->assertTrue($foundPayment);
    }

    public function testIntercomQueueUserPaymentFailed()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomQueueUserPaymentFailed', $this),
            'bar'
        );
        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onUserPaymentFailedEvent(new UserPaymentEvent($user, 'foo'));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }

    public function testIntercomQueueClaimCreated()
    {
        $claim = new Claim();
        static::$dm->persist($claim);
        static::$dm->flush();

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onClaimCreatedEvent(new ClaimEvent($claim));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($claim->getId(), $data['claimId']);
        $this->assertEquals(IntercomService::QUEUE_EVENT_CLAIM_CREATED, $data['action']);
    }

    public function testIntercomQueueClaimApproved()
    {
        $claim = new Claim();
        static::$dm->persist($claim);
        static::$dm->flush();

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onClaimApprovedEvent(new ClaimEvent($claim));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($claim->getId(), $data['claimId']);
        $this->assertEquals(IntercomService::QUEUE_EVENT_CLAIM_APPROVED, $data['action']);
    }

    public function testIntercomQueueClaimSettled()
    {
        $claim = new Claim();
        static::$dm->persist($claim);
        static::$dm->flush();

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onClaimSettledEvent(new ClaimEvent($claim));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($claim->getId(), $data['claimId']);
        $this->assertEquals(IntercomService::QUEUE_EVENT_CLAIM_SETTLED, $data['action']);
    }

    public function testIntercomQueuePicSure()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testIntercomQueuePicSure', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));
        $s3File = new PicSureFile();

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPicSureDamagedEvent(new PicsureEvent($policy, $s3File));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPicSureInvalidEvent(new PicsureEvent($policy, $s3File));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPicSureReceivedEvent(new PicsureEvent($policy, $s3File));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPicSureUndamagedEvent(new PicsureEvent($policy, $s3File));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }
}
