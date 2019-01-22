<?php

namespace AppBundle\Tests\Service;

use AppBundle\Classes\SoSure;
use AppBundle\Document\DateTrait;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Service\FeatureService;
use AppBundle\Service\JudopayService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\Feature;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Classes\Salva;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Document\Payment\PolicyDiscountPayment;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Service\\JudopayServiceTest
 */
class JudopayServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use DateTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var JudopayService */
    protected static $judopay;
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
        /** @var JudopayService $judopay */
        $judopay = self::$container->get('app.judopay');
        self::$judopay = $judopay;
        /** @var FeatureService $feature */
        $feature = self::$container->get('app.feature');
        $feature->setEnabled(Feature::FEATURE_PAYMENT_PROBLEM_INTERCOM, true);
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

    public function testJudoPaymentPolicyNoReload()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoPaymentPolicyNoReload', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $paymentA = new JudoPayment();
        $paymentA->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $paymentA->setSuccess(false);
        $policy->addPayment($paymentA);
        static::$dm->persist($paymentA);
        static::$dm->flush();

        $payment = new JudoPayment();
        $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $policy->addPayment($payment);
        static::$dm->persist($payment);
        static::$dm->flush();

        $transactionDetails = self::$judopay->testPayDetails(
            $user,
            $payment->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        $receiptId = $transactionDetails['receiptId'];

        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token', Payment::SOURCE_WEB_API);
        $this->assertEquals('Success', $payment->getResult());

        // We must be able to access the new policy on the policy without reloading the db record
        $this->assertEquals(2, count($policy->getPayments()));
        $this->assertFalse($policy->getPayments()[0]->isSuccess());
        $this->assertTrue($policy->getPayments()[1]->isSuccess());
    }

    public function testJudoReceiptMonthly()
    {
        $user = $this->createValidUser(static::generateEmail('judo-receipt', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token', Payment::SOURCE_WEB_API);
        $this->assertEquals($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(), $payment->getAmount());
        $this->assertEquals($payment->getAmount() / (1 + $policy->getPremium()->getIptRate()), $payment->getGwp());
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals('Success', $payment->getResult());

        $tokens = $policy->getPolicyOrPayerOrUserJudoPaymentMethod()->getCardTokens();
        $this->assertEquals(1, count($tokens));
        $data = json_decode($tokens['token']);
        $this->assertEquals(self::$JUDO_TEST_CARD_LAST_FOUR, $data->cardLastfour);
        $this->assertEquals(str_replace('/', '', self::$JUDO_TEST_CARD_EXP), $data->endDate);
    }

    public function testJudoReceiptYearly()
    {
        $user = $this->createValidUser(static::generateEmail('judo-receipt-yearly', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token', Payment::SOURCE_WEB_API);
        $this->assertEquals($phone->getCurrentPhonePrice()->getYearlyPremiumPrice(), $payment->getAmount());
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals('Success', $payment->getResult());
    }

    public function testJudoReceiptTooOld()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoReceiptTooOld', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token', Payment::SOURCE_WEB_API);
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals('Success', $payment->getResult());

        self::$judopay->getReceipt($receiptId, false, true);
        $exception = false;
        try {
            self::$judopay->getReceipt($receiptId, false, true, new \DateTime('-3 hours'));
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertTrue($exception);
    }

    public function testJudoReceiptRefunded()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoReceiptRefunded', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token', Payment::SOURCE_WEB_API);
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals('Success', $payment->getResult());

        $refund = self::$judopay->refund($payment, 0.01);
        $this->assertEquals('Success', $refund->getResult());

        self::$judopay->getReceipt($receiptId, false);
        $exception = false;
        try {
            self::$judopay->getReceipt($receiptId, true);
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertTrue($exception);
    }

    public function testJudoReceiptRefundFull()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoReceiptRefundFull', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();
        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token', Payment::SOURCE_WEB_API);
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals('Success', $payment->getResult());

        $refund = self::$judopay->refund($payment, $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $this->assertEquals('Success', $refund->getResult());

        self::$judopay->getReceipt($receiptId, false);
        $exception = false;
        try {
            self::$judopay->getReceipt($receiptId, true);
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertTrue($exception);
    }

    public function testJudoReceiptPaymentDiff()
    {
        $user = $this->createValidUser(static::generateEmail('judo-receipt-exception', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            '1.01',
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        // should be allowed
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token', Payment::SOURCE_WEB_API);

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testJudoExceptionStatusPending()
    {
        $user = $this->createValidUser(static::generateEmail('judo-exception-pending', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            '1.01',
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        try {
            $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token', JudoPayment::SOURCE_SYSTEM);
        } catch (\Exception $e) {
            // expected exception - ignore
            $this->assertNotNull($e);
        }

        $this->assertEquals(Policy::STATUS_PENDING, $policy->getStatus());
    }

    /**
     * @expectedException AppBundle\Exception\PaymentDeclinedException
     */
    public function testJudoReceiptPaymentDeclinedException()
    {
        $user = $this->createValidUser(static::generateEmail('judo-receipt-declined-exception', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            '4221 6900 0000 4963',
            '12/20',
            '125'
        );
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token', Payment::SOURCE_WEB_API);
    }

    public function testJudoAdd()
    {
        $user = $this->createValidUser(static::generateEmail('judo-add', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$judopay->add($policy, $receiptId, 'ctoken', 'token', Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($updatedPolicy->getPolicyNumber()));
    }

    public function testJudoAddDisassociate()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoAddDisassociate-user', $this));
        $payer = $this->createValidUser(static::generateEmail('testJudoAddDisassociate-payer', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);

        $policy->setPayer($payer);
        static::$dm->flush();

        $this->assertTrue($policy->isDifferentPayer());

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$judopay->add($policy, $receiptId, 'ctoken', 'token', Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');


        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());
        $this->assertFalse($updatedPolicy->isDifferentPayer());
    }

    public function testJudoAdditionalUnexpectedPayment()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoAdditionalUnexpectedPayment', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$judopay->add($policy, $receiptId, 'ctoken', 'token', Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $policy->setStatus(PhonePolicy::STATUS_UNPAID);
        static::$dm->flush();

        $scheduledPayment = $policy->getScheduledPayments()[0];
        $receiptId = self::$judopay->testPay(
            $user,
            $scheduledPayment->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$judopay->add($policy, $receiptId, 'ctoken', 'token', Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy->getStatus());
    }

    public function testJudoCommission()
    {
        $user = $this->createValidUser(static::generateEmail('judo-commission', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$judopay->add($policy, $receiptId, 'ctoken', 'token', Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
    }

    public function testJudoRefund()
    {
        $user = $this->createValidUser(static::generateEmail('judo-refund', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );
        static::$policyService->setEnvironment('prod');
        self::$judopay->add($policy, $receiptId, 'ctoken', 'token', Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));

        $payment = $policy->getLastSuccessfulUserPaymentCredit();

        $refund = self::$judopay->refund($payment);
        $this->assertEquals('Success', $refund->getResult());
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

    /** @expectedException \Judopay\Exception\ApiException */
    public function testJudoRefundExceeded()
    {
        $user = $this->createValidUser(static::generateEmail('judo-refund-exceeded', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$judopay->add($policy, $receiptId, 'ctoken', 'token', Payment::SOURCE_WEB_API);
        static::$policyService->setEnvironment('test');

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));

        $payment = $policy->getPayments()[0];

        $refund = self::$judopay->refund($payment, $payment->getAmount() + 0.01);
    }

    /**
     * @expectedException \Exception
     */
    public function testJudoScheduledPaymentNotRunnable()
    {
        $user = $this->createValidUser(static::generateEmail('judo-scheduled-unrunable', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN,
            $policy->getId()
        );
        if (!isset($details['cardDetails']) || $details['result'] != JudoPayment::RESULT_SUCCESS) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));

        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $scheduledPayment = $policy->getScheduledPayments()[0];

        self::$judopay->scheduledPayment($scheduledPayment, 'TEST');
    }

    public function testJudoScheduledPayment()
    {
        $user = $this->createValidUser(static::generateEmail('judo-scheduled', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN,
            $policy->getId()
        );
        if (!isset($details['cardDetails']) || $details['result'] != JudoPayment::RESULT_SUCCESS) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}"
        );
        // @codingStandardsIgnoreEnd

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

        self::$judopay->scheduledPayment($scheduledPayment, 'TEST', $nextMonth);
        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice() * 2 + 1, $policy->getPremiumPaid());

        static::$dm->clear();
        /** @var ScheduledPaymentRepository $repo */
        $repo = static::$dm->getRepository(ScheduledPayment::class);
        /** @var ScheduledPayment $updatedScheduledPayment */
        $updatedScheduledPayment = $repo->find($scheduledPayment->getId());
        $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $updatedScheduledPayment->getStatus());
        $this->assertTrue($updatedScheduledPayment->getPayment()->isSuccess());
    }

    public function testJudoScheduledPaymentDelayed()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoScheduledPaymentDelayed', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN,
            $policy->getId()
        );
        if (!isset($details['cardDetails']) || $details['result'] != JudoPayment::RESULT_SUCCESS) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}"
        );
        // @codingStandardsIgnoreEnd

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

        self::$judopay->scheduledPayment($scheduledPayment, 'TEST', $nextMonth);
        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice() * 2 + 1, $policy->getPremiumPaid());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy->getStatus());
    }

    public function testJudoScheduledPaymentExpiredCard()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoScheduledPaymentExpiredCard', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN,
            $policy->getId()
        );
        if (!isset($details['cardDetails']) || $details['result'] != JudoPayment::RESULT_SUCCESS) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertTrue($policy->hasPolicyOrUserValidPaymentMethod());

        $policy->getPolicyOrPayerOrUserJudoPaymentMethod()->addCardToken(
            '1',
            json_encode(['cardLastfour' => '0000', 'endDate' => '0115'])
        );
        self::$dm->flush();
        $this->assertFalse($policy->hasPolicyOrUserValidPaymentMethod());

        $scheduledPayment = $policy->getScheduledPayments()[0];
        $nextMonth = \DateTime::createFromFormat('U', time());
        $nextMonth = $this->convertTimezone($nextMonth, SoSure::getSoSureTimezone());
        $nextMonth = $nextMonth->add(new \DateInterval('P1M'));

        $scheduledPayment = self::$judopay->scheduledPayment($scheduledPayment, 'TEST', $nextMonth);
        $this->assertEquals(JudoPayment::RESULT_SKIPPED, $scheduledPayment->getPayment()->getResult());
        $this->assertEquals(false, $scheduledPayment->getPayment()->isSuccess());
    }

    public function testJudoScheduledPaymentInvalidPaymentMethod()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoScheduledPaymentInvalidPaymentMethod', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN,
            $policy->getId()
        );
        if (!isset($details['cardDetails']) || $details['result'] != JudoPayment::RESULT_SUCCESS) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertTrue($policy->hasPolicyOrUserValidPaymentMethod());

        $policy->getUser()->setPaymentMethod(null);
        self::$dm->flush();
        $this->assertFalse($policy->hasPolicyOrUserValidPaymentMethod());

        $scheduledPayment = $policy->getScheduledPayments()[0];
        $nextMonth = \DateTime::createFromFormat('U', time());
        $nextMonth = $this->convertTimezone($nextMonth, SoSure::getSoSureTimezone());
        $nextMonth = $nextMonth->add(new \DateInterval('P1M'));

        $scheduledPayment = self::$judopay->scheduledPayment($scheduledPayment, 'TEST', $nextMonth);
        $this->assertEquals(JudoPayment::RESULT_SKIPPED, $scheduledPayment->getPayment()->getResult());
        $this->assertEquals(false, $scheduledPayment->getPayment()->isSuccess());
    }

    public function testProcessTokenPayResult()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('judo-process-token', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        static::$dm->persist($policy);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        if (!isset($details['cardDetails']) || !isset($details['cardDetails']['cardToken'])) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(self::$JUDO_TEST_CARD_LAST_FOUR, $policy->getPayments()[0]->getCardLastFour());

        $scheduledPayment = $policy->getNextScheduledPayment();
        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setPolicy($policy);

        self::$judopay->processScheduledPaymentResult($scheduledPayment, $payment);
        $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertEquals(11, count($policy->getScheduledPayments()));

        // Payment failures should be rescheduled
        $initialScheduledPayment = $policy->getNextScheduledPayment();
        for ($i = 1; $i <= 3; $i++) {
            $scheduledPayment = $policy->getNextScheduledPayment();
            $this->assertLessThan(
                29,
                $scheduledPayment->getScheduled()->diff($initialScheduledPayment->getScheduled())->days
            );
            if ($i > 1) {
                $this->assertGreaterThan($initialScheduledPayment->getScheduled(), $scheduledPayment->getScheduled());
            }
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
            $this->assertEquals(11 + $i, count($policy->getScheduledPayments()));
        }

        // A further failed payment should not add another scheduled payment
        $scheduledPayment = $policy->getNextScheduledPayment();
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
        // 11 + 3 (failed 1 scheduled payment + 3 rescheduled payments = 4 weeks)
        $this->assertEquals(14, count($policy->getScheduledPayments()));
        static::$dm->flush();

        //\Doctrine\Common\Util\Debug::dump($policy);
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(ScheduledPayment::class);
        $this->assertEquals(4, $repo->countUnpaidScheduledPayments($policy));
    }

    /**
     * @expectedException AppBundle\Exception\SameDayPaymentException
     */
    public function testMultipleSameDayPayments()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testMultipleSameDayPayments', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        static::$dm->persist($policy);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        if (!isset($details['cardDetails']) || !isset($details['cardDetails']['cardToken'])) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_TOKEN,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(self::$JUDO_TEST_CARD_LAST_FOUR, $policy->getPayments()[0]->getCardLastFour());

        $scheduledPayment = $policy->getNextScheduledPayment();
        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setPolicy($policy);

        self::$judopay->processScheduledPaymentResult($scheduledPayment, $payment);
        $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertEquals(11, count($policy->getScheduledPayments()));

        $initialScheduledPayment = $policy->getNextScheduledPayment();
        $initialScheduledPayment->setScheduled(\DateTime::createFromFormat('U', time()));
        self::$judopay->scheduledPayment($initialScheduledPayment, 'TEST');
    }

    private function mockMailerSend($times)
    {
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $mailer->expects($this->exactly($times))->method('send');
        self::$judopay->getMailer()->setMailer($mailer);

        return $mailer;
    }

    public function testPaymentFirstProblem()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testPaymentFirstProblem', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        static::$dm->persist($policy);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        if (!isset($details['cardDetails']) || !isset($details['cardDetails']['cardToken'])) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(self::$JUDO_TEST_CARD_LAST_FOUR, $policy->getPayments()[0]->getCardLastFour());

        $mock = $this->mockMailerSend(1);

        // 1st failure (expected email; total = 1)
        // print '1/1st failure' . PHP_EOL;
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
        $this->assertNull($policy->getPolicyOrPayerOrUserJudoPaymentMethod()->getFirstProblem());
        $mock->__phpunit_verify();

        // 2nd failure - should trigger problem  (expected no email; total = 1)
        // print '1/2nd failure' . PHP_EOL;
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
        $this->assertEquals(
            $scheduledPayment->getScheduled(),
            $policy->getPolicyOrPayerOrUserJudoPaymentMethod()->getFirstProblem()
        );

        // 3rd failure - nothing (expected no email; total = 1)
        // print '1/3rd failure' . PHP_EOL;
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
        $this->assertNotEquals(
            $scheduledPayment->getScheduled(),
            $policy->getPolicyOrPayerOrUserJudoPaymentMethod()->getFirstProblem()
        );

        // 4th - success (expected no email; total = 1)
        // print '1/4th success' . PHP_EOL;
        $scheduledPayment = $policy->getNextScheduledPayment();
        // print_r($scheduledPayment->getScheduled());
        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_SUCCESS);

        self::$judopay->processScheduledPaymentResult($scheduledPayment, $payment);
        $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $mock->__phpunit_verify();
        $mock = $this->mockMailerSend(1);
        // 1st failure (expected email; total = 2)
        // print '2/1st failure' . PHP_EOL;
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

    public function testRemainderPaymentCancelledPolicy()
    {
        $user = $this->createValidUser(static::generateEmail('testRemainderPaymentCancelledPolicy', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        static::$dm->persist($policy);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        if (!isset($details['cardDetails']) || !isset($details['cardDetails']['cardToken'])) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(self::$JUDO_TEST_CARD_LAST_FOUR, $policy->getPayments()[0]->getCardLastFour());

        $policy->cancel(Policy::CANCELLED_COOLOFF);
        static::$dm->flush();

        $details = self::$judopay->testPayDetails(
            $user,
            sprintf('%sUP', $policy->getId()),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice() * 11,
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        //\Doctrine\Common\Util\Debug::dump($policy);
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());
        $this->assertEquals(0, $updatedPolicy->getOutstandingPremium());
    }

    public function testFailedProcessScheduledPaymentResult()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testFailedProcessScheduledPaymentResult', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        static::$dm->persist($policy);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        if (!isset($details['cardDetails']) || !isset($details['cardDetails']['cardToken'])) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, mb_strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(self::$JUDO_TEST_CARD_LAST_FOUR, $policy->getPayments()[0]->getCardLastFour());

        $scheduledPayment = $policy->getNextScheduledPayment();
        self::$judopay->processScheduledPaymentResult($scheduledPayment, null);
        $this->assertEquals(ScheduledPayment::STATUS_FAILED, $scheduledPayment->getStatus());
    }

    public function testCommission()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('commission', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        if (!isset($details['cardDetails']) || !isset($details['cardDetails']['cardToken'])) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION,
            $policy->getLastSuccessfulUserPaymentCredit()->getTotalCommission()
        );

        for ($i = 1; $i <= 10; $i++) {
            $scheduledPayment = $policy->getNextScheduledPayment();
            $payment = new JudoPayment();
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $payment->setAmount($scheduledPayment->getAmount());
            $policy->addPayment($payment);

            self::$judopay->setCommission($payment);

            self::$judopay->processScheduledPaymentResult(
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

        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setAmount($scheduledPayment->getAmount());
        $policy->addPayment($payment);

        self::$judopay->setCommission($payment);

        self::$judopay->processScheduledPaymentResult(
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

    public function testCardExpiringEmail()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testCardExpiringEmail', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        if (!isset($details['cardDetails']) || !isset($details['cardDetails']['cardToken'])) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());

        $this->assertEquals(
            new \DateTime('2021-01-01 00:00:00', SoSure::getSoSureTimezone()),
            $policy->getPolicyOrPayerOrUserJudoPaymentMethod()->getCardEndDateAsDate()
        );
        $this->assertFalse(self::$judopay->cardExpiringEmail($policy));
        $this->assertTrue(self::$judopay->cardExpiringEmail($policy, new \DateTime('2020-12-15')));
    }

    public function testFailedPaymentEmail()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testFailedPaymentEmail', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        static::$dm->persist($policy);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        if (!isset($details['cardDetails']) || !isset($details['cardDetails']['cardToken'])) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        for ($i = 1; $i < 4; $i++) {
            $scheduledPayment = $policy->getNextScheduledPayment();
            $payment = new JudoPayment();
            $payment->setResult(JudoPayment::RESULT_DECLINED);
            $payment->setPolicy($policy);
            $policy->addPayment($payment);

            self::$judopay->processScheduledPaymentResult(
                $scheduledPayment,
                $payment,
                clone $scheduledPayment->getScheduled()
            );

            self::assertEquals(count($policy->getFailedPayments()), $i);

            $this->assertEquals(
                'AppBundle:Email:card/failedPayment',
                self::$judopay->failedPaymentEmail($policy, count($policy->getFailedPayments()))
            );
        }
    }

    public function testFailedPaymentEmailMissing()
    {
        $this->clearEmail(static::$container);
        $user = $this->createValidUser(static::generateEmail('testFailedPaymentEmail', $this, true));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);
        static::$dm->persist($policy);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        if (!isset($details['cardDetails']) || !isset($details['cardDetails']['cardToken'])) {
            throw new \Exception('Payment failed');
        }

        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        $user->setPaymentMethod(new JudoPaymentMethod());

        for ($i = 1; $i < 4; $i++) {
            $scheduledPayment = $policy->getNextScheduledPayment();
            $payment = new JudoPayment();
            $payment->setResult(JudoPayment::RESULT_DECLINED);
            $payment->setPolicy($policy);
            $policy->addPayment($payment);

            self::$judopay->processScheduledPaymentResult(
                $scheduledPayment,
                $payment,
                clone $scheduledPayment->getScheduled()
            );

            self::assertEquals(count($policy->getFailedPayments()), $i);

            $this->assertEquals(
                'AppBundle:Email:card/cardMissing',
                self::$judopay->failedPaymentEmail($policy, count($policy->getFailedPayments()))
            );
        }
    }

    public function testJudoExisting()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoMultiPolicy', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy1 = static::initPolicy($user, static::$dm, $phone, null, false, false);
        $policy2 = static::initPolicy($user, static::$dm, $phone);
        $policy3 = static::initPolicy($user, static::$dm, $phone);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy1->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$judopay->add(
            $policy1,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API
        );
        static::$policyService->setEnvironment('test');

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy1 = $repo->find($policy1->getId());

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy1->getStatus());
        $this->assertGreaterThan(5, mb_strlen($updatedPolicy1->getPolicyNumber()));

        static::$policyService->setEnvironment('prod');
        self::$judopay->existing($policy2, $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        static::$policyService->setEnvironment('test');

        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy2 = $repo->find($policy2->getId());

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy2->getStatus());
        $this->assertGreaterThan(5, mb_strlen($updatedPolicy2->getPolicyNumber()));

        static::$policyService->setEnvironment('prod');
        $invalidPremium = false;
        try {
            self::$judopay->existing($policy3, 0.01);
        } catch (InvalidPremiumException $e) {
            $invalidPremium = true;
        }
        $this->assertTrue($invalidPremium);

        $invalidPremium = false;
        try {
            self::$judopay->existing($policy3, $phone->getCurrentPhonePrice()->getYearlyPremiumPrice() + 0.01);
        } catch (InvalidPremiumException $e) {
            $invalidPremium = true;
        }
        $this->assertTrue($invalidPremium);

        self::$judopay->existing($policy3, $phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
        static::$policyService->setEnvironment('test');

        $updatedPolicy3 = $repo->find($policy3->getId());

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy3->getStatus());
        $this->assertGreaterThan(5, mb_strlen($updatedPolicy3->getPolicyNumber()));
    }

    public function testJudoCommissionAmounts()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoCommissionAmounts', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getYearlyPremiumPrice());
        $policy->addPayment($payment);
        self::$judopay->setCommission($payment);
        $this->assertEquals(
            Salva::YEARLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );

        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $policy->addPayment($payment);
        self::$judopay->setCommission($payment);
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );

        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice() * 3);
        $policy->addPayment($payment);
        self::$judopay->setCommission($payment);
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION * 3,
            $payment->getTotalCommission()
        );

        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice() * 1.5);
        $policy->addPayment($payment);
        self::$judopay->setCommission($payment);
        $this->assertNull($payment->getTotalCommission());
    }

    public function testJudoCommissionAmountsWithDiscount()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoCommissionAmountsWithDiscount', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

        $discount = new PolicyDiscountPayment();
        $discount->setAmount(10);
        $discount->setDate(\DateTime::createFromFormat('U', time()));
        $policy->addPayment($discount);
        $policy->getPremium()->setAnnualDiscount($discount->getAmount());

        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getAdjustedYearlyPremiumPrice());
        $policy->addPayment($payment);
        self::$judopay->setCommission($payment);
        $this->assertEquals(
            Salva::YEARLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );

        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $policy->addPayment($payment);
        self::$judopay->setCommission($payment);
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );

        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice() * 3);
        $policy->addPayment($payment);
        self::$judopay->setCommission($payment);
        $this->assertEquals(
            Salva::MONTHLY_TOTAL_COMMISSION * 3,
            $payment->getTotalCommission()
        );

        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice() * 1.5);
        $policy->addPayment($payment);
        self::$judopay->setCommission($payment);
        $this->assertNull($payment->getTotalCommission());

        $payment = new JudoPayment();
        $payment->setSuccess(true);
        $payment->setAmount($policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice() * 11);
        $policy->addPayment($payment);
        self::$judopay->setCommission($payment);

        $payment = new JudoPayment();
        $payment->setSuccess(true);
        $payment->setAmount($policy->getPremium()->getAdjustedFinalMonthlyPremiumPrice());
        $policy->addPayment($payment);
        $this->assertEquals(0, $policy->getOutstandingPremium());

        self::$judopay->setCommission($payment);
        $this->assertEquals(
            Salva::FINAL_MONTHLY_TOTAL_COMMISSION,
            $payment->getTotalCommission()
        );
    }

    public function testJudoCommissionActual()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoCommissionActual', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, false);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $policy->getPremium()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API
        );
        static::$policyService->setEnvironment('test');

        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);

        $amount = $updatedPolicy->getPremium()->getMonthlyPremiumPrice() * 11;
        $this->assertEquals($updatedPolicy->getOutstandingPremium(), $amount);

        // build server is too fast and appears to be adding both payments at the same time
        sleep(1);

        $details = self::$judopay->testPayDetails(
            $user,
            sprintf('%sR', $policy->getId()),
            $amount,
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        static::$policyService->setEnvironment('prod');
        self::$judopay->add(
            $updatedPolicy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
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
}
