<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Service\PolicyService;
use AppBundle\Service\SixpackService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use AppBundle\Listener\SixpackListener;

use AppBundle\Event\PolicyEvent;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Listener\\SixpackListenerTest
 */
class SixpackListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $userRepo;
    /** @var SixpackService */
    protected static $sixpackService;
    /** @var PolicyService */
    protected static $policyService;

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
        self::$sixpackService = self::$container->get('app.sixpack');
        self::$policyService = self::$container->get('app.policy');
    }

    public function tearDown()
    {
    }

    public function testSixpackPolicyActual()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSixpackPolicyActual', $this),
            'bar'
        );

        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);

        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());
    }

    public function testSixpackListener()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSixpackListener', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        $listener = new SixpackListener(static::$sixpackService);
        $listener->onPolicyCreatedEvent(new PolicyEvent($policy));

        // difficult to test via framework, but at least execute code to check for errors
        $this->assertTrue(true);
    }
}
