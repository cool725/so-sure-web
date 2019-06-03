<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\Attribution;
use AppBundle\Service\HubspotService;
use AppBundle\Listener\HubspotListener;
use AppBundle\Event\UserEvent;
use AppBundle\Event\PolicyEvent;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Listener\\HubspotListenerTest
 */
class HubspotListenerTest extends WebTestCase
{
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $userManager;
    protected static $policyService;
    protected static $hubspotService;
    protected static $hubspotListener;
    protected static $redis;

    public static function setUpBeforeClass()
    {
        $kernel = static::createKernel();
        $kernel->boot();
        self::$container = $kernel->getContainer();
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$hubspotService = self::$container->get('app.hubspot');
        self::$hubspotListener = self::$container->get('app.listener.hubspot');
        self::$policyService = self::$container->get('app.policy');
        self::$redis = self::$container->get('snc_redis.default');
    }

    public function tearDown()
    {
    }

    /**
     * Tests a message is queued when a user is created, and another when the user's policy is started.
     */
    public function testOnUserCreatedAndPolicyStart()
    {
        self::$hubspotService->clearQueue();
        $policy = $this->createPhonePolicy("userCreatedAndPolicyStart");
        $queue = self::$hubspotService->getQueueData();
        $this->checkQueue($queue, [HubspotService::QUEUE_UPDATE_USER]);
        self::$policyService->create($policy, null, true);
        $queue = self::$hubspotService->getQueueData();
        $this->checkQueue(
            $queue,
            [
                HubspotService::QUEUE_UPDATE_USER,
                HubspotService::QUEUE_UPDATE_POLICY,
                HubspotService::QUEUE_UPDATE_POLICY
            ]
        );
    }

    /**
     * Tests a message is queued when a user is updated.
     */
    public function testOnUserUpdatedEvent()
    {
        self::$hubspotService->clearQueue();
        $policy = $this->createPhonePolicy("userUpdated");
        // creation of user.
        $list = [HubspotService::QUEUE_UPDATE_USER];
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
        // update email.
        $policy->getUser()->setEmail("bingbingwahoo@hotmail.com");
        self::$dm->flush();
        $list[] = HubspotService::QUEUE_UPDATE_USER;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
        // update first name.
        $policy->getUser()->setFirstName("Kim");
        self::$dm->flush();
        $list[] = HubspotService::QUEUE_UPDATE_USER;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
        // update last name.
        $policy->getUser()->setLastName("Kubus");
        self::$dm->flush();
        $list[] = HubspotService::QUEUE_UPDATE_USER;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
        // update facebook id.
        $policy->getUser()->setFacebookId("abc123jifwe");
        self::$dm->flush();
        $list[] = HubspotService::QUEUE_UPDATE_USER;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
        // update attribution.
        $policy->getUser()->setAttribution(new Attribution());
        self::$dm->flush();
        $list[] = HubspotService::QUEUE_UPDATE_USER;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
        // update latest attribution.
        $policy->getUser()->setLatestAttribution(new Attribution());
        self::$dm->flush();
        $list[] = HubspotService::QUEUE_UPDATE_USER;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
        // update mobile number.
        $policy->getUser()->setMobileNumber("07123456789");
        self::$dm->flush();
        $list[] = HubspotService::QUEUE_UPDATE_USER;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
        // update gender
        $policy->getUser()->setGender("male");
        self::$dm->flush();
        $list[] = HubspotService::QUEUE_UPDATE_USER;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
    }

    /**
     * Tests that policy events are caught and actioned correctly.
     */
    public function testPolicyEvents()
    {
        self::$hubspotService->clearQueue();
        $policy = $this->createPhonePolicy("policyEvents");
        $queue = self::$hubspotService->getQueueData();
        $list = [HubspotService::QUEUE_UPDATE_USER];
        $this->checkQueue($queue, $list);
        // create.
        self::$policyService->create($policy, null, true);
        $list[] = HubspotService::QUEUE_UPDATE_POLICY;
        $list[] = HubspotService::QUEUE_UPDATE_POLICY;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
        // unpaid.
        self::$hubspotListener->onPolicyUpdatedEvent(new PolicyEvent($policy));
        $list[] = HubspotService::QUEUE_UPDATE_POLICY;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
        // cancelled.
        self::$hubspotListener->onPolicyUpdatedEvent(new PolicyEvent($policy));
        $list[] = HubspotService::QUEUE_UPDATE_POLICY;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
        // reactivated.
        self::$hubspotListener->onPolicyUpdatedEvent(new PolicyEvent($policy));
        $list[] = HubspotService::QUEUE_UPDATE_POLICY;
        $this->checkQueue(self::$hubspotService->getQueueData(), $list);
    }

    /**
     * Creates a policy and user with a phone and persisted, but not initialised.
     * @param string $name is the name of the email account that the policy holder will be given.
     * @return Policy the new policy created.
     */
    private function createPhonePolicy($name)
    {
        $user = $this->createUser(
            static::$userManager,
            static::generateEmail($name, $this),
            'bar'
        );
        $policy = $this->initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        self::$dm->persist($policy);
        self::$dm->persist($policy->getUser());
        self::$dm->flush();
        return $policy;
    }

    /**
     * Checks if the queue has the things in it that it ought to have at this time.
     * @param array   $queue   is the queue to check.
     * @param array   $actions is the list of actions which the queue should be checked to contain in order.
     * @param boolean $length  The queue must always have at least as many elements as the actions array, but when this
     *                         is true, it also may not have more.
     */
    private function checkQueue($queue, $actions, $length = true)
    {
        //var_dump($queue);
        $n = count($actions);
        if ($length) {
            $this->assertEquals($n, count($queue));
        } else {
            $this->assertTrue(count($queue) >= $n);
        }
        for ($i = 0; $i < $n; $i++) {
            $data = unserialize($queue[$i]);
            $this->assertEquals($actions[$i], $data["action"]);
        }
    }
}
