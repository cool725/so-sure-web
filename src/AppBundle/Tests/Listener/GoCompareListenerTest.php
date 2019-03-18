<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\Attribution;
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
 * AppBundle\\Tests\\Listener\\GoCompareListenerTest
 */
class GoCompareListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $userRepo;
    protected static $intercomService;
    protected static $goCompare;

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
        self::$goCompare = self::$container->get('app.listener.gocompare');
    }

    public function tearDown()
    {
    }

    public function testGoCompareListenerUrl()
    {
        /** @var User $user */
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testGoCompareListenerActual', $this, true),
            'bar'
        );
        $attribution = new Attribution();
        $attribution->setGoCompareQuote(123);
        $user->setAttribution($attribution);

        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());

        $url = self::$goCompare->getGoCompareTrackingUrl($policy);
        $this->assertContains('123', $url);
    }
}
