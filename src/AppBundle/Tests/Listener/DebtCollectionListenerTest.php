<?php

namespace AppBundle\Tests\Listener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\UserListener;
use AppBundle\Listener\DoctrineUserListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\UserEvent;
use AppBundle\Classes\Salva;
use AppBundle\Document\User;
use AppBundle\Document\Payment\PolicyDiscountPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\DebtCollectionPayment;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Service\SalvaExportService;
use AppBundle\Listener\DebtCollectionListener;
use AppBundle\Event\PaymentEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-net
 */
class DebtCollectionListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $userRepo;
    protected static $judopayService;
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
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');
        self::$redis = self::$container->get('snc_redis.default');
        self::$judopayService = self::$container->get('app.judopay');
        self::$logger = self::$container->get('logger');

        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
    }

    public function tearDown()
    {
    }

    public function testPaymentSuccessListenerWithDebt()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPaymentSuccessListener', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());

        $policy->cancel(Policy::CANCELLED_USER_REQUESTED);
        $policy->setDebtCollector(Policy::DEBT_COLLECTOR_WISE);

        $payment = static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(null) * 11,
            Salva::MONTHLY_TOTAL_COMMISSION * 11
        );

        $listener = new DebtCollectionListener(static::$dm, static::$logger);
        $listener->onPaymentSuccessEvent(new PaymentEvent($payment));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());
        $foundDebt = false;
        foreach ($updatedPolicy->getAllPayments() as $payment) {
            if ($payment instanceof DebtCollectionPayment) {
                $foundDebt = true;
                $this->assertEquals(-20, $payment->getAmount());
            }
        }
        $this->assertTrue($foundDebt);
    }

    public function testPaymentSuccessListenerWithoutDebt()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPaymentSuccessListenerWithoutDebt', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());

        $policy->cancel(Policy::CANCELLED_USER_REQUESTED);
        //$policy->setDebtCollector(Policy::DEBT_COLLECTOR_WISE);

        $payment = static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(null) * 11,
            Salva::MONTHLY_TOTAL_COMMISSION * 11
        );

        $listener = new DebtCollectionListener(static::$dm, static::$logger);
        $listener->onPaymentSuccessEvent(new PaymentEvent($payment));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());
        $foundDebt = false;
        foreach ($updatedPolicy->getAllPayments() as $payment) {
            if ($payment instanceof DebtCollectionPayment) {
                $foundDebt = true;
            }
        }
        $this->assertFalse($foundDebt);
    }
}
