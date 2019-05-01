<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\Claim;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Service\BacsService;
use AppBundle\Service\CheckoutpayService;
use AppBundle\Service\CheckoutService;
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
use AppBundle\Document\Payment\PolicyDiscountRefundPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Service\SalvaExportService;
use AppBundle\Listener\RefundListener;
use AppBundle\Event\PolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Listener\\RefundListenerTest
 */
class RefundListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $userRepo;
    protected static $checkoutpayService;
    protected static $redis;
    protected static $logger;
    /** @var BacsService */
    protected static $bacsService;

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
        self::$checkoutpayService = self::$container->get('app.checkout');
        self::$logger = self::$container->get('logger');
        /** @var BacsService $bacsService */
        $bacsService = self::$container->get('app.bacs');
        self::$bacsService = $bacsService;

        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
    }

    public function tearDown()
    {
    }

    public function testRefundListenerCancelledNonPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundListenerCancelledNonPolicy', $this, true),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);

        $this->assertFalse($policy->isPolicy());

        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));
    }

    public function testRefundListenerCancelled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('refund-cancelled', $this, true),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());

        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));
    }

    public function testRefundListenerCancelledCooloff()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundListenerCancelledCooloff', $this, true),
            'bar'
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-11-01'),
            false
        );
        static::prepCheckoutPaymentToAdd($policy, new \DateTime('2016-11-01'));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-11-01'));
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());
        
        // simulate a free month - checkout refund + so-sure addition
        static::addPayment(
            $policy,
            0 - $policy->getPremium()->getMonthlyPremiumPrice(null, new \DateTime('2016-11-01')),
            0 - Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-11-01')
        );
        static::addSoSureStandardPayment($policy, new \DateTime('2016-11-01'));

        $policy->setCancelledReason(PhonePolicy::CANCELLED_COOLOFF);
        $policy->setStatus(PhonePolicy::STATUS_CANCELLED);
        static::$dm->flush();

        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($policy->getId());
        $totalSoSure = Payment::sumPayments($policy->getPayments(), false, SoSurePayment::class);
        $this->assertTrue($this->areEqualToTwoDp(0, $totalSoSure['total']));

        $total = Payment::sumPayments($policy->getPayments(), false);
        $this->assertTrue($this->areEqualToTwoDp(0, $total['total']));
        
        // checkout initial, checkout refund for free month
        // so-sure payment to offset checkout refund, so-sure refund for cancellation
        $this->assertEquals(4, count($policy->getPayments()));
    }

    public function testRefundListenerCancelledCooloffYearly()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundListenerCancelledCooloffYearly', $this, true),
            'bar'
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-11-01'),
            false
        );
        static::prepCheckoutPaymentToAdd($policy, new \DateTime('2016-11-01'), false);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-11-01'));
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());
        
        // simulate a free month - checkout refund + so-sure addition
        static::addPayment(
            $policy,
            0 - $policy->getPremium()->getMonthlyPremiumPrice(null, new \DateTime('2016-11-01')),
            0 - Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2016-11-01')
        );
        static::addSoSureStandardPayment($policy, new \DateTime('2016-11-01'));

        $policy->setCancelledReason(PhonePolicy::CANCELLED_COOLOFF);
        $policy->setStatus(PhonePolicy::STATUS_CANCELLED);
        static::$dm->flush();

        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($policy->getId());
        $totalSoSure = Payment::sumPayments($policy->getPayments(), false, SoSurePayment::class);
        $this->assertTrue($this->areEqualToTwoDp(0, $totalSoSure['total']));

        $total = Payment::sumPayments($policy->getPayments(), false);
        $this->assertTrue($this->areEqualToTwoDp(0, $total['total']));
        
        // checkout initial, checkout refund for free month
        // so-sure payment to offset checkout refund, so-sure refund for cancellation
        $this->assertEquals(5, count($policy->getPayments()));
    }

    public function testRefundListenerClaimNoRefundYearly()
    {
        $oneMonthAgo = \DateTime::createFromFormat('U', time());
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P1M'));
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundListenerClaimNoRefundYearly', $this, true),
            'bar'
        );
        /** @var Policy $policy */
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $oneMonthAgo,
            false
        );
        static::prepCheckoutPaymentToAdd($policy, $oneMonthAgo, false);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $oneMonthAgo);
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());

        $claim = new Claim();
        // policy service cancel would transition claim to pending closed prior to refund service
        $claim->setStatus(Claim::STATUS_PENDING_CLOSED);
        $policy->addClaim($claim);

        $policy->setCancelledReason(PhonePolicy::CANCELLED_USER_REQUESTED);
        $policy->setStatus(PhonePolicy::STATUS_CANCELLED);
        $policy->setEnd(\DateTime::createFromFormat('U', time()));
        static::$dm->flush();

        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($policy->getId());

        $total = Payment::sumPayments($policy->getPayments(), false);
        $this->assertTrue($this->areEqualToTwoDp(
            $policy->getPremium()->getAdjustedYearlyPremiumPrice(),
            $total['total']
        ));

        // checkout initial, checkout refund for free month
        // so-sure payment to offset checkout refund, so-sure refund for cancellation
        $this->assertEquals(1, count($policy->getPayments()));
    }

    public function testRefundListenerBacsCancelledCooloffYearlyManual()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundListenerBacsCancelledCooloffYearlyManual', $this, true),
            'bar'
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-11-01'),
            false
        );
        static::addBacsPayPayment($policy, new \DateTime('2016-11-01'), false);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-11-01'));
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());

        $policy->setCancelledReason(PhonePolicy::CANCELLED_COOLOFF);
        $policy->setStatus(PhonePolicy::STATUS_CANCELLED);
        static::$dm->flush();

        $now = \DateTime::createFromFormat('U', time());
        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));

        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);
        $scheduledPayment = $updatedPolicy->getNextScheduledPayment();
        $totalBacs = Payment::sumPayments($policy->getPayments(), false, BacsPayment::class);
        $this->assertTrue($this->areEqualToTwoDp(0 - $scheduledPayment->getAmount(), $totalBacs['total']));
    }

    public function testRefundListenerBacsCancelledCooloffYearlyNonManual()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundListenerBacsCancelledCooloffYearlyNonManual', $this, true),
            'bar'
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-11-01'),
            false
        );
        static::addBacsPayPayment($policy, new \DateTime('2016-11-01'), false, false);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-11-01'));
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());

        $policy->setCancelledReason(PhonePolicy::CANCELLED_COOLOFF);
        $policy->setStatus(PhonePolicy::STATUS_CANCELLED);
        static::$dm->flush();

        $now = \DateTime::createFromFormat('U', time());
        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));

        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);
        $scheduledPayment = $updatedPolicy->getNextScheduledPayment();
        $totalBacs = Payment::sumPayments($policy->getPayments(), false, BacsPayment::class);
        $this->assertTrue($this->areEqualToTwoDp(0 - $scheduledPayment->getAmount(), $totalBacs['total']));
    }

    public function testRefundFreeNovPromo()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundFreeNovPromo', $this, true),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, false);
        static::prepCheckoutPaymentToAdd($policy, new \DateTime('2016-11-01'));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-11-01'));
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());
        $this->assertEquals(Policy::PROMO_FREE_NOV, $policy->getPromoCode());

        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->refundFreeMonthPromo(new PolicyEvent($policy));
    }

    public function testRefundFreeDec2016Promo()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundFreeDec2016Promo', $this, true),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, false);
        static::prepCheckoutPaymentToAdd($policy, new \DateTime('2016-12-01'));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-12-01'));
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());
        $this->assertEquals(Policy::PROMO_FREE_DEC_2016, $policy->getPromoCode());

        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->refundFreeMonthPromo(new PolicyEvent($policy));
    }

    public function testRefundListenerPolicyDiscountUnpaid()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundListenerPolicyDiscountUnpaid', $this, true),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, false);
        static::prepCheckoutPaymentToAdd($policy, new \DateTime('2016-01-01'));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $renewalPolicy = $this->getRenewalPolicy($policy);
        static::$dm->persist($renewalPolicy);

        $policy->setPotValue(10);
        $renewalPolicy->renew(10, false, new \DateTime('2016-12-15'));

        // TODO: create actual connection on initial policy, and expire that here
        // instead of creating payment to avoid triggering the updatepot which will wipe
        // the simulated pot value
        $discount = new PolicyDiscountPayment();
        $discount->setAmount(10);
        $discount->setDate(new \DateTime('2017-01-01'));
        $renewalPolicy->addPayment($discount);
        static::$dm->persist($discount);

        $this->assertEquals(
            10,
            Payment::sumPayments($renewalPolicy->getPayments(), false, PolicyDiscountPayment::class)['total']
        );
        $renewalPolicy->setCancelledReason(PhonePolicy::CANCELLED_USER_REQUESTED);
        $renewalPolicy->setStatus(PhonePolicy::STATUS_CANCELLED);
        $renewalPolicy->setEnd(new \DateTime('2017-01-01'));
        static::$dm->flush();

        $this->assertTrue($renewalPolicy->isRefundAllowed());
        $this->assertTrue($renewalPolicy->hasPolicyDiscountPresent());

        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->onPolicyCancelledEvent(new PolicyEvent($renewalPolicy, new \DateTime('2017-01-01')));

        $this->assertNotNull($renewalPolicy->getCashback());
        // 10 - (10 * 1/365) = 9.97
        $this->assertTrue($this->areEqualToTwoDp(9.97, $renewalPolicy->getCashback()->getAmount()));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertNotNull($updatedRenewalPolicy->getCashback());
        // 10 - (10 * 1/365) = 9.97
        $this->assertTrue($this->areEqualToTwoDp(9.97, $updatedRenewalPolicy->getCashback()->getAmount()));
    }

    public function testRefundListenerPolicyDiscountPaid()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundListenerPolicyDiscountPaid', $this, true),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, false);
        static::prepCheckoutPaymentToAdd($policy, new \DateTime('2016-01-01'));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $renewalPolicy = $this->getRenewalPolicy($policy);
        static::$dm->persist($renewalPolicy);

        $policy->setPotValue(10);
        $renewalPolicy->renew(10, false, new \DateTime('2016-12-15'));

        // TODO: create actual connection on initial policy, and expire that here
        // instead of creating payment to avoid triggering the updatepot which will wipe
        // the simulated pot value
        $discount = new PolicyDiscountPayment();
        $discount->setAmount(10);
        $discount->setDate(new \DateTime('2017-01-01'));
        $renewalPolicy->addPayment($discount);
        static::$dm->persist($discount);

        $this->assertEquals(
            10,
            Payment::sumPayments($renewalPolicy->getPayments(), false, PolicyDiscountPayment::class)['total']
        );

        static::prepCheckoutPaymentToAdd($renewalPolicy, new \DateTime('2017-01-01'));

        $renewalPolicy->setCancelledReason(PhonePolicy::CANCELLED_USER_REQUESTED);
        $renewalPolicy->setStatus(PhonePolicy::STATUS_CANCELLED);
        $renewalPolicy->setEnd(new \DateTime('2017-01-20'));
        static::$dm->flush();

        $this->assertTrue($renewalPolicy->isRefundAllowed());
        $this->assertTrue($renewalPolicy->hasPolicyDiscountPresent());

        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->onPolicyCancelledEvent(new PolicyEvent($renewalPolicy, new \DateTime('2017-01-20')));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertNotNull($updatedRenewalPolicy->getCashback());
        // 10 - (10 * 20/365) = 9.45
        $this->assertTrue($this->areEqualToTwoDp(9.45, $updatedRenewalPolicy->getCashback()->getAmount()));

        $checkout = Payment::sumPayments($updatedRenewalPolicy->getPayments(), false, CheckoutPayment::class);
        $this->assertEquals(1, $checkout['numRefunded']);
        $this->assertTrue($checkout['total'] > 0);
        $this->assertTrue($checkout['received'] > 0);
        $this->assertTrue($checkout['refunded'] < 0);

        $refund = Payment::sumPayments($updatedRenewalPolicy->getPayments(), false, PolicyDiscountRefundPayment::class);
        $this->assertEquals(1, $refund['numRefunded']);
        $this->assertTrue($this->areEqualToTwoDp(-9.45, $refund['total']), $refund['total']);
        $this->assertTrue($refund['received'] == 0);
        $this->assertTrue($refund['refunded'] < 0);

        $foundRefund = false;
        foreach ($updatedRenewalPolicy->getPayments() as $payment) {
            /** @var Payment $payment */
            if ($payment instanceof PolicyDiscountRefundPayment) {
                $foundRefund = true;
                $this->assertNotNull($payment->getNotes());
            }
        }
        $this->assertTrue($foundRefund);

        $total = Payment::sumPayments($updatedRenewalPolicy->getPayments(), false);
        $this->assertEquals(2, $total['numReceived']);
        $this->assertEquals(2, $total['numRefunded']);
        $this->assertTrue($total['total'] > 0);
        $this->assertTrue($total['received'] > 0);
        $this->assertTrue($total['refunded'] < 0);
    }

    public function testRefundListenerPolicyDiscountPaidCooloff()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRefundListenerPolicyDiscountPaidCooloff', $this, true),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, false);
        static::prepCheckoutPaymentToAdd($policy, new \DateTime('2016-01-01'));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $renewalPolicy = $this->getRenewalPolicy($policy);
        static::$dm->persist($renewalPolicy);

        $policy->setPotValue(10);
        $renewalPolicy->renew(10, false, new \DateTime('2016-12-15'));

        // TODO: create actual connection on initial policy, and expire that here
        // instead of creating payment to avoid triggering the updatepot which will wipe
        // the simulated pot value
        $discount = new PolicyDiscountPayment();
        $discount->setAmount(10);
        $discount->setDate(new \DateTime('2017-01-01'));
        $renewalPolicy->addPayment($discount);
        static::$dm->persist($discount);

        $this->assertEquals(
            10,
            Payment::sumPayments($renewalPolicy->getPayments(), false, PolicyDiscountPayment::class)['total']
        );

        static::prepCheckoutPaymentToAdd($renewalPolicy, new \DateTime('2017-01-01'), true, 10/12);

        $renewalPolicy->setCancelledReason(PhonePolicy::CANCELLED_COOLOFF);
        $renewalPolicy->setStatus(PhonePolicy::STATUS_CANCELLED);
        $renewalPolicy->setEnd(new \DateTime('2017-01-05'));
        static::$dm->flush();

        $this->assertTrue($renewalPolicy->isRefundAllowed());
        $this->assertTrue($renewalPolicy->hasPolicyDiscountPresent());

        $listener = new RefundListener(
            static::$dm,
            static::$checkoutpayService,
            static::$logger,
            'test',
            self::$bacsService
        );
        $listener->onPolicyCancelledEvent(new PolicyEvent($renewalPolicy, new \DateTime('2017-01-05')));

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertNotNull($updatedRenewalPolicy->getCashback());
        $this->assertTrue($this->areEqualToTwoDp(10, $updatedRenewalPolicy->getCashback()->getAmount()));

        $checkout = Payment::sumPayments($updatedRenewalPolicy->getPayments(), false, CheckoutPayment::class);
        $this->assertEquals(1, $checkout['numRefunded']);
        $this->assertEquals(0, $checkout['total']);
        $this->assertTrue($checkout['received'] > 0);
        $this->assertTrue($checkout['refunded'] < 0);
        $this->assertEquals($checkout['received'], 0 - $checkout['refunded']);

        $total = Payment::sumPayments($updatedRenewalPolicy->getPayments(), false);
        $this->assertEquals(2, $total['numReceived']);
        $this->assertEquals(2, $total['numRefunded']);
        $this->assertEquals(0, $total['total']);
        $this->assertTrue($total['received'] > 0);
        $this->assertTrue($total['refunded'] < 0);
    }

    /**
     * @param Policy $policy
     * @param null   $date
     * @param bool   $monthly
     * @param int    $adjustment
     * @throws \Exception
     * @return CheckoutPayment
     */
    private static function prepCheckoutPaymentToAdd(
        Policy $policy,
        $date = null,
        $monthly = true,
        $adjustment = 0
    ) {
        if ($monthly) {
            $policy->setPremiumInstallments(12);
            $premium = $policy->getPremium()->getMonthlyPremiumPrice(null, $date);
            $commission = Salva::MONTHLY_TOTAL_COMMISSION;
        } else {
            $policy->setPremiumInstallments(1);
            $premium = $policy->getPremium()->getYearlyPremiumPrice(null, $date);
            $commission = Salva::YEARLY_TOTAL_COMMISSION;
        }
        if ($adjustment) {
            $premium = $premium - $adjustment;
            // toTwoDp
            $premium = number_format(round($premium, 2), 2, ".", "");
        }
        $receiptId = random_int(1, 999999);
        return static::addCheckoutPayment($policy, $premium, $commission, $receiptId, $date);
    }
}
