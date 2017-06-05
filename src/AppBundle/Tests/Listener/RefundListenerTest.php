<?php

namespace AppBundle\Tests\Listener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\UserListener;
use AppBundle\Listener\DoctrineUserListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\UserEvent;
use AppBundle\Classes\Salva;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\Payment;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Service\SalvaExportService;
use AppBundle\Listener\RefundListener;
use AppBundle\Event\PolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-net
 */
class RefundListenerTest extends WebTestCase
{
    use CurrencyTrait;
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $dm;
    protected static $userRepo;
    protected static $userManager;
    protected static $judopayService;
    protected static $policyService;
    protected static $redis;
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
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');
        self::$redis = self::$container->get('snc_redis.default');
        self::$judopayService = self::$container->get('app.judopay');
        self::$logger = self::$container->get('logger');
    }

    public function tearDown()
    {
    }

    public function testRefundListenerCancelled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('refund-cancelled', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());
        
        $listener = new RefundListener(static::$dm, static::$judopayService, static::$logger, 'test');
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));
    }

    public function testRefundListenerCancelledCooloff()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundListenerCancelledCooloff', $this),
            'bar'
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-11-01'),
            false
        );
        static::addJudoPayPayment(self::$judopayService, $policy, new \DateTime('2016-11-01'));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-11-01'));
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());
        
        // simulate a free month - judo refund + so-sure addition
        static::addPayment(
            $policy,
            0 - $policy->getPremium()->getMonthlyPremiumPrice(new \DateTime('2016-11-01')),
            0 - Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-11-01')
        );
        static::addSoSureStandardPayment($policy, new \DateTime('2016-11-01'));

        $policy->setCancelledReason(PhonePolicy::CANCELLED_COOLOFF);
        $policy->setStatus(PhonePolicy::STATUS_CANCELLED);
        static::$dm->flush();

        $listener = new RefundListener(static::$dm, static::$judopayService, static::$logger, 'test');
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($policy->getId());
        $totalSoSure = Payment::sumPayments($policy->getPayments(), false, SoSurePayment::class);
        $this->assertTrue($this->areEqualToTwoDp(0, $totalSoSure['total']));

        $total = Payment::sumPayments($policy->getPayments(), false);
        $this->assertTrue($this->areEqualToTwoDp(0, $total['total']));
        
        // judo initial, judo refund for free month
        // so-sure payment to offset judo refund, so-sure refund for cancellation
        $this->assertEquals(4, count($policy->getPayments()));
    }

    public function testRefundListenerCancelledCooloffYearly()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundListenerCancelledCooloffYearly', $this),
            'bar'
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-11-01'),
            false
        );
        static::addJudoPayPayment(self::$judopayService, $policy, new \DateTime('2016-11-01'), false);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-11-01'));
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());
        
        // simulate a free month - judo refund + so-sure addition
        static::addPayment(
            $policy,
            0 - $policy->getPremium()->getMonthlyPremiumPrice(new \DateTime('2016-11-01')),
            0 - Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-11-01')
        );
        static::addSoSureStandardPayment($policy, new \DateTime('2016-11-01'));

        $policy->setCancelledReason(PhonePolicy::CANCELLED_COOLOFF);
        $policy->setStatus(PhonePolicy::STATUS_CANCELLED);
        static::$dm->flush();

        $listener = new RefundListener(static::$dm, static::$judopayService, static::$logger, 'test');
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($policy->getId());
        $totalSoSure = Payment::sumPayments($policy->getPayments(), false, SoSurePayment::class);
        $this->assertTrue($this->areEqualToTwoDp(0, $totalSoSure['total']));

        $total = Payment::sumPayments($policy->getPayments(), false);
        $this->assertTrue($this->areEqualToTwoDp(0, $total['total']));
        
        // judo initial, judo refund for free month
        // so-sure payment to offset judo refund, so-sure refund for cancellation
        $this->assertEquals(5, count($policy->getPayments()));
    }

    public function testRefundFreeNovPromo()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundFreeNovPromo', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, false);
        static::addJudoPayPayment(self::$judopayService, $policy, new \DateTime('2016-11-01'));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-11-01'));
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());
        $this->assertEquals(Policy::PROMO_FREE_NOV, $policy->getPromoCode());

        $listener = new RefundListener(static::$dm, static::$judopayService, static::$logger, 'test');
        $listener->refundFreeMonthPromo(new PolicyEvent($policy));
    }

    public function testRefundFreeDec2016Promo()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundFreeDec2016Promo', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, false);
        static::addJudoPayPayment(self::$judopayService, $policy, new \DateTime('2016-12-01'));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-12-01'));
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());
        $this->assertEquals(Policy::PROMO_FREE_DEC_2016, $policy->getPromoCode());

        $listener = new RefundListener(static::$dm, static::$judopayService, static::$logger, 'test');
        $listener->refundFreeMonthPromo(new PolicyEvent($policy));
    }
}
