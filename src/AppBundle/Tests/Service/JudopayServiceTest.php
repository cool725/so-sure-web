<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Classes\Salva;
use AppBundle\Exception\InvalidPremiumException;

/**
 * @group functional-net
 */
class JudopayServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $judopay;
    protected static $dm;
    protected static $userRepo;
    protected static $userManager;
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
         self::$judopay = self::$container->get('app.judopay');
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$userRepo = self::$dm->getRepository(User::class);
         self::$userManager = self::$container->get('fos_user.user_manager');
         self::$policyService = self::$container->get('app.policy');
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
        $address->setLine1('10 Finsbury Square');
        $address->setCity('London');
        $address->setPostcode('EC1V 1RS');
        $user->setBillingAddress($address);

        static::$dm->persist($address);
        static::$dm->flush();

        return $user;
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

        $tokens = $user->getPaymentMethod()->getCardTokens();
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
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token', Payment::SOURCE_WEB_API);
        // should be allowed
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
            $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token');
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
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

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
        $this->assertGreaterThan(5, strlen($updatedPolicy->getPolicyNumber()));
    }

    public function testJudoAdditionalUnexpectedPayment()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoAdditionalUnexpectedPayment', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

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
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

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
        $this->assertGreaterThan(5, strlen($policy->getPolicyNumber()));
    }

    public function testJudoRefund()
    {
        $user = $this->createValidUser(static::generateEmail('judo-refund', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

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
        $this->assertGreaterThan(5, strlen($policy->getPolicyNumber()));

        $payment = $policy->getLastSuccessfulPaymentCredit();

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
        $policy = static::initPolicy($user, static::$dm, $phone, null, false, true);

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
        $this->assertGreaterThan(5, strlen($policy->getPolicyNumber()));

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
        $this->assertGreaterThan(5, strlen($policy->getPolicyNumber()));

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
        $this->assertGreaterThan(5, strlen($policy->getPolicyNumber()));

        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice(), $policy->getPremiumPaid());
        $scheduledPayment = $policy->getScheduledPayments()[0];
        $scheduledPayment->setAmount($scheduledPayment->getAmount() + 1);
        $nextMonth = new \DateTime();
        $nextMonth->add(new \DateInterval('P1M'));

        self::$judopay->scheduledPayment($scheduledPayment, 'TEST', $nextMonth);
        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice() * 2 + 1, $policy->getPremiumPaid());
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
        $this->assertGreaterThan(5, strlen($policy->getPolicyNumber()));

        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();

        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice(), $policy->getPremiumPaid());
        $scheduledPayment = $policy->getScheduledPayments()[0];
        $scheduledPayment->setAmount($scheduledPayment->getAmount() + 1);
        $nextMonth = new \DateTime();
        $nextMonth->add(new \DateInterval('P1M'));

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
        $this->assertGreaterThan(5, strlen($policy->getPolicyNumber()));
        $this->assertTrue($policy->getUser()->hasValidPaymentMethod());

        $policy->getUser()->getPaymentMethod()->addCardToken(
            '1',
            json_encode(['cardLastfour' => '0000', 'endDate' => '0115'])
        );
        self::$dm->flush();
        $this->assertFalse($policy->getUser()->hasValidPaymentMethod());

        $scheduledPayment = $policy->getScheduledPayments()[0];
        $nextMonth = new \DateTime();
        $nextMonth->add(new \DateInterval('P1M'));

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
        $this->assertGreaterThan(5, strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(self::$JUDO_TEST_CARD_LAST_FOUR, $policy->getPayments()[0]->getCardLastFour());

        $scheduledPayment = $policy->getNextScheduledPayment();
        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_SUCCESS);

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
            $policy->getLastSuccessfulPaymentCredit()->getTotalCommission()
        );

        for ($i = 1; $i <= 10; $i++) {
            $scheduledPayment = $policy->getNextScheduledPayment();
            $payment = new JudoPayment();
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $payment->setAmount($scheduledPayment->getAmount());
            self::$judopay->setCommission($policy, $payment);

            self::$judopay->processScheduledPaymentResult(
                $scheduledPayment,
                $payment,
                clone $scheduledPayment->getScheduled()
            );
            $policy->addPayment($payment);
            $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $scheduledPayment->getStatus());
            $this->assertEquals(
                Salva::MONTHLY_TOTAL_COMMISSION,
                $policy->getLastSuccessfulPaymentCredit()->getTotalCommission()
            );
        }

        $this->assertTrue($policy->isFinalMonthlyPayment());
        $scheduledPayment = $policy->getNextScheduledPayment();
        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setAmount($scheduledPayment->getAmount());
        self::$judopay->setCommission($policy, $payment);

        self::$judopay->processScheduledPaymentResult(
            $scheduledPayment,
            $payment,
            clone $scheduledPayment->getScheduled()
        );
        $policy->addPayment($payment);
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
        $this->assertFalse(self::$judopay->cardExpiringEmail($policy));
        $this->assertTrue(self::$judopay->cardExpiringEmail($policy, new \DateTime('2020-12-15')));
    }

    public function testJudoExisting()
    {
        $user = $this->createValidUser(static::generateEmail('testJudoMultiPolicy', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy1 = static::initPolicy($user, static::$dm, $phone, null, false, true);
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
        $this->assertGreaterThan(5, strlen($updatedPolicy1->getPolicyNumber()));

        static::$policyService->setEnvironment('prod');
        self::$judopay->existing($policy2, $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        static::$policyService->setEnvironment('test');

        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy2 = $repo->find($policy2->getId());

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $updatedPolicy2->getStatus());
        $this->assertGreaterThan(5, strlen($updatedPolicy2->getPolicyNumber()));

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
        $this->assertGreaterThan(5, strlen($updatedPolicy3->getPolicyNumber()));
    }
}
