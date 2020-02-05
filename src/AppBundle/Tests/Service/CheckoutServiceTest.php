<?php

namespace AppBundle\Tests\Service;

use AppBundle\Classes\SoSure;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Exception\CommissionException;
use AppBundle\Exception\InvalidPaymentMethodException;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Service\CheckoutService;
use AppBundle\Service\FeatureService;
use AppBundle\Service\JudopayService;
use com\checkout\ApiServices\Cards\ResponseModels\Card;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\Feature;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Classes\Salva;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Document\Payment\PolicyDiscountPayment;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Service\\CheckoutServiceTest
 */
class CheckoutServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use DateTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var CheckoutService */
    protected static $checkout;
    protected static $userRepo;

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
        /** @var CheckoutService $checkout */
        $checkout = self::$container->get('app.checkout');
        self::$checkout = $checkout;
    }

    public function tearDown()
    {
    }

    private function createValidUser($email)
    {
        $user = static::createUser(self::$userManager, $email, 'foo');
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $user->setMobileNumber(static::generateRandomMobile());
        $user->setBirthday(new \DateTime('1980-01-01'));
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1('5 Martin Lane');
        $address->setCity('London');
        $address->setPostcode('EC4R 0DP');
        $user->setBillingAddress($address);

        static::$dm->persist($address);
        static::$dm->flush();

        return $user;
    }

    public function testCheckoutPaymentPolicyNoReload()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutPaymentPolicyNoReload', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

        $checkout = new CheckoutPaymentMethod();
        $checkout->setCustomerId('ctoken');
        $checkout->addCardToken('token', null);
        $policy->setPaymentMethod($checkout);
        static::$dm->flush();

        $paymentA = new CheckoutPayment();
        $paymentA->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $paymentA->setSuccess(false);
        $policy->addPayment($paymentA);
        static::$dm->persist($paymentA);
        static::$dm->flush();

        $payment = new CheckoutPayment();
        $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $policy->addPayment($payment);
        static::$dm->persist($payment);
        static::$dm->flush();

        $transactionDetails = self::$checkout->testPayDetails(
            $policy,
            $payment->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        $this->assertNotNull($transactionDetails);
        if ($transactionDetails) {
            $receiptId = $transactionDetails->getId();

            $payment = self::$checkout->validateCharge($policy, $receiptId, Payment::SOURCE_WEB_API);
            $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $payment->getResult());

            // We must be able to access the new policy on the policy without reloading the db record
            $this->assertEquals(2, count($policy->getPayments()));
            $this->assertFalse($policy->getPayments()[0]->isSuccess());
            $this->assertTrue($policy->getPayments()[1]->isSuccess());
        }
    }

    public function testCheckoutReceiptMonthly()
    {
        $user = $this->createValidUser(static::generateEmail('checkout-receipt', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        static::$dm->flush();

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        $payment = self::$checkout->validateCharge($policy, $receiptId, Payment::SOURCE_WEB_API);
        $this->assertEquals($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(), $payment->getAmount());
        $this->assertEquals($payment->getAmount() / (1 + $policy->getPremium()->getIptRate()), $payment->getGwp());
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $payment->getResult());

        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);
        $checkout = $updatedPolicy->getCheckoutPaymentMethod();
        $this->assertEquals(1, count($checkout->getCardTokens()));
        $this->assertEquals(self::$CHECKOUT_TEST_CARD_LAST_FOUR, $checkout->getCardLastFour());
        $this->assertEquals(str_replace('/', '', self::$CHECKOUT_TEST_CARD_EXP), $checkout->getCardEndDate());
    }

    public function testCheckoutReceiptYearly()
    {
        $user = $this->createValidUser(static::generateEmail('checkout-receipt-yearly', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $checkout = new CheckoutPaymentMethod();
        $checkout->setCustomerId('ctoken');
        $checkout->addCardToken('token', null);
        $policy->setPaymentMethod($checkout);
        static::$dm->flush();

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        $payment = self::$checkout->validateCharge($policy, $receiptId, Payment::SOURCE_WEB_API);
        $this->assertEquals($phone->getCurrentPhonePrice()->getYearlyPremiumPrice(), $payment->getAmount());
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $payment->getResult());
    }

    /**
     * Historic issue for 67.31 vs 67.32 when paid annually
     *
     * @throws \AppBundle\Exception\PaymentDeclinedException
     * @throws \AppBundle\Exception\ProcessedException
     */
    public function testCheckoutReceiptYearlyPenny()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutReceiptYearlyPenny', $this, true));
        $phone = static::getPhoneByPrice(static::$dm, 5.61);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $checkout = new CheckoutPaymentMethod();
        $checkout->setCustomerId('ctoken');
        $checkout->addCardToken('token', null);
        $policy->setPaymentMethod($checkout);
        static::$dm->flush();

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        $payment = self::$checkout->validateCharge($policy, $receiptId, Payment::SOURCE_WEB_API);
        $this->assertEquals($phone->getCurrentPhonePrice()->getYearlyPremiumPrice(), $payment->getAmount());
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $payment->getResult());
    }

    public function testCheckoutReceiptTooOld()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutReceiptTooOld', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $checkout = new CheckoutPaymentMethod();
        $checkout->setCustomerId('ctoken');
        $checkout->addCardToken('token', null);
        $policy->setPaymentMethod($checkout);
        static::$dm->flush();

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        $payment = self::$checkout->validateCharge($policy, $receiptId, Payment::SOURCE_WEB_API);
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $payment->getResult());

        self::$checkout->getCharge($receiptId, false, true);
        $exception = false;
        try {
            self::$checkout->getCharge($receiptId, false, true, new \DateTime('-3 hours'));
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertTrue($exception);
    }

    public function testCheckoutReceiptRefunded()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutReceiptRefunded', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $checkout = new CheckoutPaymentMethod();
        $checkout->setCustomerId('ctoken');
        $checkout->addCardToken('token', null);
        $policy->setPaymentMethod($checkout);
        static::$dm->flush();

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        $payment = self::$checkout->validateCharge($policy, $receiptId, Payment::SOURCE_WEB_API);
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $payment->getResult());

        $refund = self::$checkout->refund($payment, 0.01);
        $this->assertEquals(CheckoutPayment::RESULT_REFUNDED, $refund->getResult());

        self::$checkout->getCharge($receiptId, false);
        $exception = false;
        try {
            self::$checkout->getCharge($receiptId, true);
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertTrue($exception);
    }

    public function testCheckoutReceiptRefundFull()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutReceiptRefundFull', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $checkout = new CheckoutPaymentMethod();
        $checkout->setCustomerId('ctoken');
        $checkout->addCardToken('token', null);
        $policy->setPaymentMethod($checkout);
        static::$dm->flush();

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        $payment = self::$checkout->validateCharge($policy, $receiptId, Payment::SOURCE_WEB_API);
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $payment->getResult());

        $refund = self::$checkout->refund($payment, $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $this->assertEquals(CheckoutPayment::RESULT_REFUNDED, $refund->getResult());

        self::$checkout->getCharge($receiptId, false);
        $exception = false;
        try {
            self::$checkout->getCharge($receiptId, true);
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertTrue($exception);
    }

    public function testCheckoutReceiptPaymentDiff()
    {
        $user = $this->createValidUser(static::generateEmail('checkout-receipt-exception', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $checkout = new CheckoutPaymentMethod();
        $checkout->setCustomerId('ctoken');
        $checkout->addCardToken('token', null);
        $policy->setPaymentMethod($checkout);
        static::$dm->flush();

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            '1.01',
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        $payment = self::$checkout->validateCharge($policy, $receiptId, Payment::SOURCE_WEB_API);

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testCheckoutExceptionStatusPending()
    {
        $user = $this->createValidUser(static::generateEmail('checkout-exception-pending', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $checkout = new CheckoutPaymentMethod();
        $checkout->setCustomerId('ctoken');
        $checkout->addCardToken('token', null);
        $policy->setPaymentMethod($checkout);
        static::$dm->flush();

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            '1.01',
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        try {
            $payment = self::$checkout->validateCharge($policy, $receiptId, Payment::SOURCE_WEB_API);
        } catch (\Exception $e) {
            // expected exception - ignore
            $this->assertNotNull($e);
        }

        $this->assertEquals(Policy::STATUS_PENDING, $policy->getStatus());
    }

    /**
     * @expectedException AppBundle\Exception\PaymentDeclinedException
     */
    public function testCheckoutReceiptPaymentDeclinedException()
    {
        $user = $this->createValidUser(
            static::generateEmail(
                'testCheckoutReceiptPaymentDeclinedException',
                $this,
                true
            )
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $checkout = new CheckoutPaymentMethod();
        $checkout->setCustomerId('ctoken');
        $checkout->addCardToken('token', null);
        $policy->setPaymentMethod($checkout);
        static::$dm->flush();

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            self::$CHECKOUT_TEST_CARD_FAIL_AMOUNT,
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        $payment = self::$checkout->validateCharge($policy, $receiptId, Payment::SOURCE_WEB_API);
    }

    public function testCheckoutAdd()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutAdd', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );

        static::$policyService->setEnvironment('prod');
        self::$checkout->add($policy, $receiptId, Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($updatedPolicy->getPolicyNumber()));
    }

    public function testCheckoutAddDisassociate()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutAddDisassociate-user', $this, true));
        $payer = $this->createValidUser(static::generateEmail('testCheckoutAddDisassociate-payer', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $policy->setPayer($payer);
        static::$dm->flush();

        $this->assertTrue($policy->isDifferentPayer());

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$checkout->add($policy, $receiptId, Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);
        $this->assertFalse($updatedPolicy->isDifferentPayer());
    }

    public function testCheckoutAdditionalUnexpectedPayment()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutAdditionalUnexpectedPayment', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$checkout->add($policy, $receiptId, Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $policy->setStatus(PhonePolicy::STATUS_UNPAID);
        static::$dm->flush();

        $scheduledPayment = $policy->getScheduledPayments()[0];
        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$checkout->add($policy, $receiptId, Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy->getStatus());
    }

    public function testCheckoutRefund()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutRefund', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$checkout->add($policy, $receiptId, Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));

        $payment = $policy->getLastSuccessfulUserPaymentCredit();

        $refund = self::$checkout->refund($payment);
        $this->assertEquals(CheckoutPayment::RESULT_REFUNDED, $refund->getResult());
        $this->assertEquals(0 - $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(), $refund->getAmount());

        $calculator = JudoPayment::sumPayments([$payment, $refund], false);
        $this->assertEquals(2, $calculator['numPayments']);

        $this->assertEquals(0, $calculator['total']);
        $this->assertEquals(0, $calculator['totalCommission']);
        $this->assertEquals(0, $calculator['coverholderCommission']);
        $this->assertEquals(0, $calculator['brokerCommission']);
        $this->assertEquals(0, $calculator['totalUnderwriter']);
        $this->assertEquals($calculator['received'], 0 - $calculator['refunded']);
    }

    /**
     * @expectedException \com\checkout\helpers\ApiHttpClientCustomException
     */
    public function testCheckoutRefundExceeded()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutRefundExceeded', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $receiptId = self::$checkout->testPay(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$checkout->add($policy, $receiptId, Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));

        $payment = $policy->getPayments()[0];

        $refund = self::$checkout->refund($payment, $payment->getAmount() + 0.01);
    }

    /**
     * @expectedException \Exception
     */
    public function testCheckoutScheduledPaymentNotRunnable()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutScheduledPaymentNotRunnable', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        // @codingStandardsIgnoreStart
        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));

        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $scheduledPayment = $policy->getScheduledPayments()[0];

        self::$checkout->scheduledPayment($scheduledPayment, 'TEST');
    }

    public function testCheckoutScheduledPayment()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutScheduledPayment', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));

        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice(), $policy->getPremiumPaid());
        /** @var ScheduledPayment $scheduledPayment */
        $scheduledPayment = $policy->getScheduledPayments()[0];
        $scheduledPayment->setAmount($scheduledPayment->getAmount() + 1);
        $nextMonth = \DateTime::createFromFormat('U', time());
        $nextMonth = $this->convertTimezone($nextMonth, SoSure::getSoSureTimezone());
        $nextMonth = $nextMonth->add(new \DateInterval('P1M'));

        self::$checkout->scheduledPayment($scheduledPayment, 'TEST', $nextMonth);
        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice() * 2 + 1, $policy->getPremiumPaid());

        static::$dm->clear();
        /** @var ScheduledPaymentRepository $repo */
        $repo = static::$dm->getRepository(ScheduledPayment::class);
        /** @var ScheduledPayment $updatedScheduledPayment */
        $updatedScheduledPayment = $repo->find($scheduledPayment->getId());
        $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $updatedScheduledPayment->getStatus());
        $this->assertTrue($updatedScheduledPayment->getPayment()->isSuccess());
    }

    public function testCheckoutScheduledPaymentDelayed()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutScheduledPaymentDelayed', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));

        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();

        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice(), $policy->getPremiumPaid());
        $scheduledPayment = $policy->getScheduledPayments()[0];
        $scheduledPayment->setAmount($scheduledPayment->getAmount() + 1);
        $nextMonth = \DateTime::createFromFormat('U', time());
        $nextMonth = $this->convertTimezone($nextMonth, SoSure::getSoSureTimezone());
        $nextMonth = $nextMonth->add(new \DateInterval('P1M'));

        self::$checkout->scheduledPayment($scheduledPayment, 'TEST', $nextMonth);
        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice() * 2 + 1, $policy->getPremiumPaid());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy->getStatus());
    }

    public function testCheckoutScheduledPaymentExpiredCard()
    {
        /** @var User $user */
        $user = $this->createValidUser(static::generateEmail('testCheckoutScheduledPaymentExpiredCard', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertTrue($policy->hasPolicyOrUserValidPaymentMethod());

        $policy->getCheckoutPaymentMethod()->addCardToken(
            '1',
            json_encode(['cardLastfour' => '0000', 'endDate' => '0115'])
        );
        self::$dm->flush();
        $this->assertFalse($policy->hasPolicyOrUserValidPaymentMethod());

        $scheduledPayment = $policy->getScheduledPayments()[0];
        $nextMonth = \DateTime::createFromFormat('U', time());
        $nextMonth = $this->convertTimezone($nextMonth, SoSure::getSoSureTimezone());
        $nextMonth = $nextMonth->add(new \DateInterval('P1M'));

        $scheduledPayment = self::$checkout->scheduledPayment($scheduledPayment, 'TEST', $nextMonth);
        $this->assertEquals(JudoPayment::RESULT_SKIPPED, $scheduledPayment->getPayment()->getResult());
        $this->assertEquals(false, $scheduledPayment->getPayment()->isSuccess());
    }

    /**
     * @expectedException \AppBundle\Exception\InvalidPaymentMethodException
     */
    public function testCheckoutScheduledPaymentInvalidPaymentMethod()
    {
        $user = $this->createValidUser(
            static::generateEmail('testCheckoutScheduledPaymentInvalidPaymentMethod', $this, true)
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertTrue($policy->hasPolicyOrUserValidPaymentMethod());

        $policy->setPaymentMethod(null);
        self::$dm->flush();
        $this->assertFalse($policy->hasPolicyOrUserValidPaymentMethod());

        $scheduledPayment = $policy->getScheduledPayments()[0];
        $nextMonth = \DateTime::createFromFormat('U', time());
        $nextMonth = $this->convertTimezone($nextMonth, SoSure::getSoSureTimezone());
        $nextMonth = $nextMonth->add(new \DateInterval('P1M'));

        $scheduledPayment = self::$checkout->scheduledPayment($scheduledPayment, 'TEST', $nextMonth);
        $this->assertEquals(JudoPayment::RESULT_SKIPPED, $scheduledPayment->getPayment()->getResult());
        $this->assertEquals(false, $scheduledPayment->getPayment()->isSuccess());
    }

    public function testProcessTokenPayResult()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testProcessTokenPayResult', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());
        static::$dm->persist($policy);

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(self::$CHECKOUT_TEST_CARD_LAST_FOUR, $policy->getPayments()[0]->getCardLastFour());

        $scheduledPayment = $policy->getNextScheduledPayment();
        $payment = new CheckoutPayment();
        $payment->setResult(CheckoutPayment::RESULT_CAPTURED);
        $payment->setPolicy($policy);

        self::$checkout->processScheduledPaymentResult($scheduledPayment, $payment);
        $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertEquals(11, count($policy->getScheduledPayments()));

        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);

        // Payment failures should be rescheduled
        $initialScheduledPayment = $policy->getNextScheduledPayment();
        for ($i = 1; $i <= 3; $i++) {
            $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);
            $scheduledPayment = $updatedPolicy->getNextScheduledPayment();
            $this->assertLessThan(
                29,
                $scheduledPayment->getScheduled()->diff($initialScheduledPayment->getScheduled())->days,
                $scheduledPayment->getScheduled()->format(\DateTime::ATOM)
            );
            if ($i > 1) {
                $this->assertGreaterThan($initialScheduledPayment->getScheduled(), $scheduledPayment->getScheduled());
            }
            $payment = new CheckoutPayment();
            $payment->setResult(CheckoutPayment::RESULT_DECLINED);
            $payment->setPolicy($policy);

            self::$checkout->processScheduledPaymentResult(
                $scheduledPayment,
                $payment,
                clone $scheduledPayment->getScheduled()
            );
            $this->assertEquals(ScheduledPayment::STATUS_FAILED, $scheduledPayment->getStatus());
            $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());
            $this->assertEquals(11 + $i, count($policy->getScheduledPayments()));
        }

        // A further failed payment should not add another scheduled payment
        $scheduledPayment = $policy->getNextScheduledPayment();
        $payment = new CheckoutPayment();
        $payment->setResult(CheckoutPayment::RESULT_DECLINED);
        $payment->setPolicy($policy);

        self::$checkout->processScheduledPaymentResult(
            $scheduledPayment,
            $payment,
            clone $scheduledPayment->getScheduled()
        );
        $this->assertEquals(ScheduledPayment::STATUS_FAILED, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());
        // 11 + 3 (failed 1 scheduled payment + 3 rescheduled payments = 4 weeks)
        $this->assertEquals(14, count($policy->getScheduledPayments()));
        static::$dm->flush();

        //\Doctrine\Common\Util\Debug::dump($policy);
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(ScheduledPayment::class);
        $this->assertEquals(4, $repo->countUnpaidScheduledPayments($policy));
    }

    /**
     * @expectedException \AppBundle\Exception\SameDayPaymentException
     */
    public function testCheckoutMultipleSameDayPayments()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testMultipleSameDayPayments', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());
        static::$dm->persist($policy);

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(self::$CHECKOUT_TEST_CARD_LAST_FOUR, $policy->getPayments()[0]->getCardLastFour());

        $scheduledPayment = $policy->getNextScheduledPayment();
        $payment = new CheckoutPayment();
        $payment->setResult(CheckoutPayment::RESULT_CAPTURED);
        $payment->setSource(CheckoutPayment::SOURCE_TOKEN);
        $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $policy->addPayment($payment);

        self::$checkout->processScheduledPaymentResult($scheduledPayment, $payment);
        $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertEquals(11, count($policy->getScheduledPayments()));

        $initialScheduledPayment = $policy->getNextScheduledPayment();
        $initialScheduledPayment->setScheduled(\DateTime::createFromFormat('U', time()));
        self::$checkout->scheduledPayment($initialScheduledPayment, 'TEST');
    }

    private function mockDispatcher($times)
    {
        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
            ->setMethods(array('dispatch'))
            ->getMock();
        $dispatcher->expects($this->exactly($times))
            ->method('dispatch');
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $dispatcher;
        self::$checkout->setDispatcher($dispatcher);
        return $dispatcher;
    }

    public function testCheckoutPaymentFirstProblem()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testCheckoutPaymentFirstProblem', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());
        static::$dm->persist($policy);

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(self::$CHECKOUT_TEST_CARD_LAST_FOUR, $policy->getPayments()[0]->getCardLastFour());

        $mock = $this->mockDispatcher(2);

        // 1st failure (expected email; total = 1)
        // print '1/1st failure' . PHP_EOL;
        $scheduledPayment = $policy->getNextScheduledPayment();
        // print_r($scheduledPayment->getScheduled());
        $payment = new CheckoutPayment();
        $payment->setResult(CheckoutPayment::RESULT_DECLINED);
        $policy->addPayment($payment);

        self::$checkout->processScheduledPaymentResult(
            $scheduledPayment,
            $payment,
            clone $scheduledPayment->getScheduled()
        );
        $this->assertEquals(ScheduledPayment::STATUS_FAILED, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());
        $this->assertNull($policy->getCheckoutPaymentMethod()->getFirstProblem());
        $mock->__phpunit_verify();

        // 2nd failure - should trigger problem  (expected no email; total = 1)
        // print '1/2nd failure' . PHP_EOL;
        $scheduledPayment = $policy->getNextScheduledPayment();
        // print_r($scheduledPayment->getScheduled());
        $payment = new CheckoutPayment();
        $payment->setResult(CheckoutPayment::RESULT_DECLINED);
        $policy->addPayment($payment);

        self::$checkout->processScheduledPaymentResult(
            $scheduledPayment,
            $payment,
            clone $scheduledPayment->getScheduled()
        );
        $this->assertEquals(ScheduledPayment::STATUS_FAILED, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());

        /*
        $this->assertEquals(
            $scheduledPayment->getScheduled(),
            $policy->getPolicyOrPayerOrUserJudoPaymentMethod()->getFirstProblem()
        );
        */

        // 3rd failure - nothing (expected no email; total = 1)
        // print '1/3rd failure' . PHP_EOL;
        $scheduledPayment = $policy->getNextScheduledPayment();
        // print_r($scheduledPayment->getScheduled());
        $payment = new CheckoutPayment();
        $payment->setResult(CheckoutPayment::RESULT_DECLINED);
        $policy->addPayment($payment);

        self::$checkout->processScheduledPaymentResult(
            $scheduledPayment,
            $payment,
            clone $scheduledPayment->getScheduled()
        );
        $this->assertEquals(ScheduledPayment::STATUS_FAILED, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());
        /*
        $this->assertNotEquals(
            $scheduledPayment->getScheduled(),
            $policy->getPolicyOrPayerOrUserJudoPaymentMethod()->getFirstProblem()
        );
        */

        // 4th - success (expected no email; total = 1)
        // print '1/4th success' . PHP_EOL;
        $scheduledPayment = $policy->getNextScheduledPayment();
        // print_r($scheduledPayment->getScheduled());
        $payment = new CheckoutPayment();
        $payment->setResult(CheckoutPayment::RESULT_CAPTURED);

        self::$checkout->processScheduledPaymentResult($scheduledPayment, $payment);
        $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $mock->__phpunit_verify();
        $mock = $this->mockDispatcher(2);
        // 1st failure (expected email; total = 2)
        // print '2/1st failure' . PHP_EOL;
        $scheduledPayment = $policy->getNextScheduledPayment();
        // print_r($scheduledPayment->getScheduled());
        $payment = new CheckoutPayment();
        $payment->setResult(CheckoutPayment::RESULT_DECLINED);
        $policy->addPayment($payment);

        self::$checkout->processScheduledPaymentResult(
            $scheduledPayment,
            $payment,
            clone $scheduledPayment->getScheduled()
        );
        $this->assertEquals(ScheduledPayment::STATUS_FAILED, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());
        //$this->assertNotNull($policy->getPolicyOrPayerOrUserJudoPaymentMethod()->getFirstProblem());
        $mock->__phpunit_verify();

        /*
         * TODO: Fix this test, but will need to set dates better for scheduled payments about
         * such that $failedPayments = $repo->countUnpaidScheduledPayments($policy); works
        $mock = $this->mockMailerSend(1);
        // 2nd failure -  (expected email; total = 3)
        // print '2/2nd failure' . PHP_EOL;
        $scheduledPayment = $policy->getNextScheduledPayment();
        // print_r($scheduledPayment->getScheduled());
        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $payment->setPolicy($policy);

        self::$judopay->processScheduledPaymentResult(
            $scheduledPayment,
            $payment,
            clone $scheduledPayment->getScheduled()
        );
        $this->assertEquals(ScheduledPayment::STATUS_FAILED, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());
        $this->assertNotNull($policy->getPolicyOrPayerOrUserJudoPaymentMethod()->getFirstProblem());
        $mock->__phpunit_verify();
        */
    }

    public function testCheckoutRemainderPaymentCancelledPolicy()
    {
        $user = $this->createValidUser(
            static::generateEmail(
                'testCheckoutRemainderPaymentCancelledPolicy',
                $this,
                true
            )
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());
        static::$dm->persist($policy);

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(self::$CHECKOUT_TEST_CARD_LAST_FOUR, $policy->getPayments()[0]->getCardLastFour());

        $policy->cancel(Policy::CANCELLED_COOLOFF);
        static::$dm->flush();

        $details = self::$checkout->testPayDetails(
            $policy,
            sprintf('%sUP', $policy->getId()),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice() * 11,
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        //\Doctrine\Common\Util\Debug::dump($policy);
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());
        $this->assertEquals(0, $updatedPolicy->getOutstandingPremium());
    }

    public function testCheckoutFailedProcessScheduledPaymentResult()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(
            static::generateEmail(
                'testCheckoutFailedProcessScheduledPaymentResult',
                $this,
                true
            )
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());
        static::$dm->persist($policy);

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(self::$CHECKOUT_TEST_CARD_LAST_FOUR, $policy->getPayments()[0]->getCardLastFour());

        $scheduledPayment = $policy->getNextScheduledPayment();
        self::$checkout->processScheduledPaymentResult($scheduledPayment, null);
        $this->assertEquals(ScheduledPayment::STATUS_FAILED, $scheduledPayment->getStatus());
    }

    public function testCheckoutCommission()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testCheckoutCommission', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION,
            $policy->getLastSuccessfulUserPaymentCredit()->getTotalCommission()
        );

        for ($i = 1; $i <= 10; $i++) {
            $scheduledPayment = $policy->getNextScheduledPayment();
            $payment = new CheckoutPayment();
            $payment->setResult(CheckoutPayment::RESULT_CAPTURED);
            $payment->setAmount($scheduledPayment->getAmount());
            $policy->addPayment($payment);

            self::$checkout->setCommission($payment);

            self::$checkout->processScheduledPaymentResult(
                $scheduledPayment,
                $payment,
                clone $scheduledPayment->getScheduled()
            );
            $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $scheduledPayment->getStatus());
            $this->assertEquals(
                Salva::MONTHLY_TOTAL_COMMISSION,
                $policy->getLastSuccessfulUserPaymentCredit()->getTotalCommission()
            );
        }

        $scheduledPayment = $policy->getNextScheduledPayment();

        $this->assertTrue($policy->isFinalMonthlyPayment());
        $this->assertEquals($policy->getOutstandingPremium(), $scheduledPayment->getAmount());

        $payment = new CheckoutPayment();
        $payment->setResult(CheckoutPayment::RESULT_CAPTURED);
        $payment->setAmount($scheduledPayment->getAmount());
        $policy->addPayment($payment);

        self::$checkout->setCommission($payment);

        self::$checkout->processScheduledPaymentResult(
            $scheduledPayment,
            $payment,
            clone $scheduledPayment->getScheduled()
        );
        self::$dm->flush();
        $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $scheduledPayment->getStatus());
        $this->assertEquals(
            Salva::FINAL_MONTHLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );
    }

    public function testCheckoutCardExpiringEmail()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testCheckoutCardExpiringEmail', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());

        $this->assertEquals(
            new \DateTime('2099-02-01 00:00:00', SoSure::getSoSureTimezone()),
            $policy->getCheckoutPaymentMethod()->getCardEndDateAsDate()
        );
        $this->assertFalse(self::$checkout->cardExpiringEmail($policy));
        $this->assertTrue(self::$checkout->cardExpiringEmail($policy, new \DateTime('2099-02-15')));
    }

    public function testCheckoutFailedPaymentEmail()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testCheckoutFailedPaymentEmail', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());
        static::$dm->persist($policy);

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        for ($i = 1; $i < 4; $i++) {
            $scheduledPayment = $policy->getNextScheduledPayment();
            $payment = new CheckoutPayment();
            $payment->setResult(CheckoutPayment::RESULT_DECLINED);
            $payment->setPolicy($policy);
            $policy->addPayment($payment);

            self::$checkout->processScheduledPaymentResult(
                $scheduledPayment,
                $payment,
                clone $scheduledPayment->getScheduled()
            );

            self::assertEquals(count($policy->getFailedPayments()), $i);

            $this->assertEquals(
                'AppBundle:Email:card/failedPayment',
                self::$checkout->failedPaymentEmail($policy, count($policy->getFailedPayments()))
            );
        }
    }

    public function testCheckoutFailedPaymentEmailMissing()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testCheckoutFailedPaymentEmailMissing', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());
        static::$dm->persist($policy);

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );

        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        for ($i = 1; $i < 4; $i++) {
            $scheduledPayment = $policy->getNextScheduledPayment();
            $payment = new CheckoutPayment();
            $payment->setResult(CheckoutPayment::RESULT_DECLINED);
            $payment->setPolicy($policy);
            $policy->addPayment($payment);

            self::$checkout->processScheduledPaymentResult(
                $scheduledPayment,
                $payment,
                clone $scheduledPayment->getScheduled()
            );

            self::assertEquals(count($policy->getFailedPayments()), $i);

            $this->assertEquals(
                'AppBundle:Email:card/cardMissing',
                self::$checkout->failedPaymentEmail($policy, count($policy->getFailedPayments()))
            );
        }
    }

    public function testCheckoutExisting()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutExisting', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy1 = static::initPolicy($user, static::$dm, $phone, null, false, false);
        $policy2 = static::initPolicy($user, static::$dm, $phone);
        $policy3 = static::initPolicy($user, static::$dm, $phone);
        $policy1->setPaymentMethod(new CheckoutPaymentMethod());
        $policy2->setPaymentMethod(new CheckoutPaymentMethod());
        $policy3->setPaymentMethod(new CheckoutPaymentMethod());

        $details = self::$checkout->testPayDetails(
            $policy1,
            $policy1->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy1->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        static::$policyService->setEnvironment('prod');
        self::$checkout->add(
            $policy1,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );
        static::$policyService->setEnvironment('test');

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $updatedPolicy1 = $this->assertPolicyExists(self::$container, $policy1);

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy1->getStatus());
        $this->assertGreaterThan(5, mb_strlen($updatedPolicy1->getPolicyNumber()));

        $policy2->setPaymentMethod($policy1->getPaymentMethod());

        static::$policyService->setEnvironment('prod');
        self::$checkout->existing($policy2, $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        static::$policyService->setEnvironment('test');

        $updatedPolicy2 = $this->assertPolicyExists(self::$container, $policy2);

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy2->getStatus());
        $this->assertGreaterThan(5, mb_strlen($updatedPolicy2->getPolicyNumber()));

        $policy3->setPaymentMethod($policy1->getPaymentMethod());

        static::$policyService->setEnvironment('prod');
        $invalidPremium = false;
        try {
            self::$checkout->existing($policy3, 0.01);
        } catch (InvalidPremiumException $e) {
            $invalidPremium = true;
        }
        $this->assertTrue($invalidPremium);

        $invalidPremium = false;
        try {
            self::$checkout->existing($policy3, $phone->getCurrentPhonePrice()->getYearlyPremiumPrice() + 0.01);
        } catch (InvalidPremiumException $e) {
            $invalidPremium = true;
        }
        $this->assertTrue($invalidPremium);

        self::$checkout->existing($policy3, $phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        static::$policyService->setEnvironment('test');

        $updatedPolicy3 = $this->assertPolicyExists(self::$container, $policy3);

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy3->getStatus());
        $this->assertGreaterThan(5, mb_strlen($updatedPolicy3->getPolicyNumber()));
    }

    public function testCheckoutCommissionAmounts()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutCommissionAmounts', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());
        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getYearlyPremiumPrice());
        $policy->addPayment($payment);
        self::$checkout->setCommission($payment);
        $this->assertEquals(
            Salva::YEARLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $policy->addPayment($payment);
        self::$checkout->setCommission($payment);
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice() * 3);
        $policy->addPayment($payment);
        self::$checkout->setCommission($payment);
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION * 3,
            $payment->getTotalCommission()
        );

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice() * 1.5);
        $policy->addPayment($payment);
        self::$checkout->setCommission($payment, true);

        $this->assertGreaterThan(0, $payment->getTotalCommission());
    }

    /**
     * @expectedException AppBundle\Exception\CommissionException
     */
    public function testCheckoutCommissionForFractionThrowsExceptionWithFalse()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutCommissionAmounts', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice() * 1.5);
        $policy->addPayment($payment);
        self::$checkout->setCommission($payment);
    }

    /**
     */
    public function testCheckoutCommissionAmountsWithDiscount()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutCommissionAmountsWithDiscount', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $discount = new PolicyDiscountPayment();
        $discount->setAmount(10);
        $discount->setDate(\DateTime::createFromFormat('U', time()));
        $policy->addPayment($discount);
        $policy->getPremium()->setAnnualDiscount($discount->getAmount());

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getAdjustedYearlyPremiumPrice());
        $policy->addPayment($payment);
        self::$checkout->setCommission($payment);
        $this->assertEquals(
            Salva::YEARLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $policy->addPayment($payment);
        self::$checkout->setCommission($payment);
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice() * 3);
        $policy->addPayment($payment);
        self::$checkout->setCommission($payment);
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION * 3,
            $payment->getTotalCommission()
        );

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice() * 1.5);
        $policy->addPayment($payment);
        self::$checkout->setCommission($payment, true);
        $this->assertGreaterThan(0, $payment->getTotalCommission());

        $payment = new CheckoutPayment();
        $payment->setSuccess(true);
        $payment->setAmount($policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice() * 11);
        $policy->addPayment($payment);
        self::$checkout->setCommission($payment);

        $payment = new CheckoutPayment();
        $payment->setSuccess(true);
        $payment->setAmount($policy->getPremium()->getAdjustedFinalMonthlyPremiumPrice());
        $policy->addPayment($payment);
        $this->assertEquals(0, $policy->getOutstandingPremium());

        self::$checkout->setCommission($payment);
        $this->assertEquals(
            Salva::FINAL_MONTHLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );
    }

    public function testCheckoutCommissionActual()
    {
        $user = $this->createValidUser(static::generateEmail('testCheckoutCommissionActual', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        static::$policyService->setEnvironment('prod');
        self::$checkout->add(
            $policy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );
        static::$policyService->setEnvironment('test');

        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);

        $amount = $updatedPolicy->getPremium()->getMonthlyPremiumPrice() * 11;
        $this->assertEquals($updatedPolicy->getOutstandingPremium(), $amount);

        // build server is too fast and appears to be adding both payments at the same time
        sleep(1);

        $details = self::$checkout->testPayDetails(
            $policy,
            sprintf('%sR', $policy->getId()),
            $amount,
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }

        static::$policyService->setEnvironment('prod');
        self::$checkout->add(
            $updatedPolicy,
            $details->getId(),
            Payment::SOURCE_WEB_API
        );
        static::$policyService->setEnvironment('test');

        $updatedPolicy2 = $this->assertPolicyExists(self::$container, $policy);
        $payment = $updatedPolicy2->getLastSuccessfulUserPaymentCredit();
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION * 10 + Salva::FINAL_MONTHLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );
    }

    public function testCheckoutWithPreviousChargeIdNotSetSuccessfulTransaction()
    {
        $user = $this->createValidUser(
            static::generateEmail(
                'testCheckoutWithPreviousChargeIdNotSetSuccessfulTransaction',
                $this,
                true
            )
        );

        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        /**
         * This test creates a new user, so they will not have a previous charge
         */
        $this->assertFalse($policy->getPaymentMethod()->hasPreviousChargeId());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        /**
         * So long as the transaction was successful, the user will now have a previousChargeId set
         */
        $this->assertTrue($policy->getPaymentMethod()->hasPreviousChargeId());
    }

    public function testCheckoutWithPreviousChargeIdSetSuccessfulTransaction()
    {
        $user = $this->createValidUser(
            static::generateEmail(
                'testCheckoutWithPreviousChargeIdSetSuccessfulTransaction',
                $this,
                true
            )
        );

        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);

        /** @var CheckoutPaymentMethod $paymentMethod */
        $policy->setPaymentMethod(new CheckoutPaymentMethod());
        $paymentMethod = $policy->getPaymentMethod();
        /**
         * This test creates a new user, so they will not have a previous charge.
         * We want to set one so that we know that there is one to remove.
         */
        $paymentMethod->setPreviousChargeId("charge_test_PHPUNITTEST12345");
        $this->assertTrue($paymentMethod->hasPreviousChargeId());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        /**
         * So long as the transaction was successful, the user will still have the same previousChargeId
         */
        $this->assertEquals("charge_test_PHPUNITTEST12345", $paymentMethod->getPreviousChargeId());
    }

    public function testCheckoutWithPreviousChargeIdNotSetFailedTransaction()
    {
        $user = $this->createValidUser(
            static::generateEmail(
                'testCheckoutWithPreviousChargeIdNotSetFailedTransaction',
                $this,
                true
            )
        );

        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        /**
         * This test creates a new user, so they will not have a previous charge.
         */
        $this->assertFalse($policy->getPaymentMethod()->hasPreviousChargeId());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            self::$CHECKOUT_TEST_CARD_FAIL_AMOUNT,
            self::$CHECKOUT_TEST_CARD_FAIL_NUM,
            self::$CHECKOUT_TEST_CARD_FAIL_EXP,
            self::$CHECKOUT_TEST_CARD_FAIL_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_DECLINED, $details->getStatus());

        /**
         * We should not have a previousChargeId set here as the transaction should have failed.
         */
        $this->assertFalse($policy->getPaymentMethod()->hasPreviousChargeId());
    }

    public function testCheckoutWithPreviousChargeIdSetFailedTransaction()
    {
        $user = $this->createValidUser(
            static::generateEmail(
                'testCheckoutWithPreviousChargeIdSetFailedTransaction',
                $this,
                true
            )
        );

        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);
        $policy->setPaymentMethod(new CheckoutPaymentMethod());

        /**
         * This test creates a new user, so they will not have a previous charge.
         * We want to set one so that we know that there is one to remove on the
         * failed payment attempt.
         */
        $policy->getPaymentMethod()->setPreviousChargeId("charge_test_PHPUNITTEST12345");
        $this->assertTrue($policy->getPaymentMethod()->hasPreviousChargeId());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            self::$CHECKOUT_TEST_CARD_FAIL_AMOUNT,
            self::$CHECKOUT_TEST_CARD_FAIL_NUM,
            self::$CHECKOUT_TEST_CARD_FAIL_EXP,
            self::$CHECKOUT_TEST_CARD_FAIL_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_DECLINED, $details->getStatus());

        /**
         * We should not have a previousChargeId set here as the transaction should have failed.
         */
        $this->assertFalse($policy->getPaymentMethod()->hasPreviousChargeId());
    }

    public function testCheckoutUpdateCardUnsetChargeId()
    {
        $user = $this->createValidUser(
            static::generateEmail(
                'testCheckoutUpdateCardSetChargeId',
                $this,
                true
            )
        );

        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);

        /** @var CheckoutPaymentMethod $paymentMethod */
        $policy->setPaymentMethod(new CheckoutPaymentMethod());
        $paymentMethod = $policy->getPaymentMethod();
        /**
         * This test creates a new user, so they will not have a previous charge.
         * We want to set one so that we know that there is one to remove.
         */
        $paymentMethod->setPreviousChargeId("charge_test_PHPUNITTEST12345");
        $this->assertTrue($paymentMethod->hasPreviousChargeId());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        $token = self::$checkout->createCardToken(
            $policy,
            self::$CHECKOUT_TEST_CARD2_NUM,
            self::$CHECKOUT_TEST_CARD2_EXP,
            self::$CHECKOUT_TEST_CARD2_PIN
        );

        self::$checkout->updatePaymentMethod($policy, $token->token);
        $this->assertEquals('none', $paymentMethod->getPreviousChargeId());
    }



    public function testCheckoutUnpaidUpdateCardStatusActive()
    {
        $user = $this->createValidUser(
            static::generateEmail(
                'testCheckoutUnpaidUpdateCardStatusActive',
                $this,
                true
            )
        );

        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);

        /** @var CheckoutPaymentMethod $paymentMethod */
        $policy->setPaymentMethod(new CheckoutPaymentMethod());
        $policy->setStatus(PhonePolicy::STATUS_UNPAID);
        $paymentMethod = $policy->getPaymentMethod();
        /**
         * This test creates a new user, so they will not have a previous charge.
         * We want to set one so that we know that there is one to remove.
         */
        $paymentMethod->setPreviousChargeId("charge_test_PHPUNITTEST12345");
        $this->assertTrue($paymentMethod->hasPreviousChargeId());

        $details = self::$checkout->testPayDetails(
            $policy,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN,
            $policy->getId()
        );
        $this->assertNotNull($details);
        if (!$details) {
            return;
        }
        $this->assertEquals(CheckoutPayment::RESULT_CAPTURED, $details->getStatus());

        $this->assertEquals(PhonePolicy::STATUS_UNPAID, $policy->getStatus());
        $token = self::$checkout->createCardToken(
            $policy,
            self::$CHECKOUT_TEST_CARD2_NUM,
            self::$CHECKOUT_TEST_CARD2_EXP,
            self::$CHECKOUT_TEST_CARD2_PIN
        );

        self::$checkout->updatePaymentMethod($policy, $token->token, 9.71);
        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
    }

    public function testUpdateCustomerIdOnManualPayment()
    {
        $now = new \DateTime();
        $pastMonth = clone $now->sub(new \DateInterval("P1M"));

        $user = $this->createValidUser("testUpdateCustomerIdOnManualPayment" . time() . "@so-sure.com");
        $phone = self::getRandomPhone(self::$dm);
        $policy = self::initPolicy($user, self::$dm, $phone, null, false, false);

        self::$dm->flush();

        $payment = self::$checkout->testPay(
            $policy,
            "oldPay",
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD2_NUM,
            self::$CHECKOUT_TEST_CARD2_EXP,
            self::$CHECKOUT_TEST_CARD2_PIN
        );

        $paymentA = new CheckoutPayment();
        $paymentA->setDate($pastMonth);
        $paymentA->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $paymentA->setSource(Payment::SOURCE_WEB);
        $policy->addPayment($paymentA);
        $policy->setCommission($paymentA, true);
        $paymentA->setSuccess(true);
        $paymentA->setReceipt($payment);


        $paymentMethod = $policy->getCheckoutPaymentMethod();
        $originalId = $paymentMethod->getCustomerId();

        self::$dm->flush();

        $user->setEmail("testUpdateCustomerIdOnManualPaymentSecondEmail" . time() . "@so-sure.com");
        self::$dm->flush();

        $payment = self::$checkout->testPay(
            $policy,
            "newPay",
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );

        $paymentA = new CheckoutPayment();
        $paymentA->setDate($now);
        $paymentA->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $paymentA->setSource(Payment::SOURCE_WEB);
        $policy->addPayment($paymentA);
        $policy->setCommission($paymentA, true);
        $paymentA->setSuccess(true);
        $paymentA->setReceipt($payment);

        $newId = $paymentMethod->getCustomerId();

        self::assertNotEquals($originalId, $newId);
    }
}
