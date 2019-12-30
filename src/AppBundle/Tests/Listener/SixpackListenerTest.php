<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Service\PolicyService;
use AppBundle\Service\SixpackService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Listener\SixpackListener;
use AppBundle\Event\PolicyEvent;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;

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
    /** @var DocumentManager */
    protected static $dm;
    protected static $userRepo;
    /** @var SixpackService */
    protected static $sixpackService;
    /** @var PolicyService */
    protected static $policyService;
    /** @var LoggerInterface */
    protected static $logger;

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
        /** @var SixpackService $sixpackService */
        $sixpackService = self::$container->get('app.sixpack');
        self::$sixpackService = $sixpackService;
        /** @var PolicyService $policyService */
        $policyService = self::$container->get('app.policy');
        self::$policyService = $policyService;
        /** @var LoggerInterface $logger */
        $logger = self::$container->get('logger');
        self::$logger = $logger;
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
        $policy = new HelvetiaPhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        $listener = new SixpackListener(static::$sixpackService, static::$logger);
        $listener->onPolicyCreatedEvent(new PolicyEvent($policy));

        // difficult to test via framework, but at least execute code to check for errors
        $this->assertTrue(true);
    }
}
