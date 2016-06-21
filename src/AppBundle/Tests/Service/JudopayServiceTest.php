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
use AppBundle\Document\ScheduledPayment;

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
        $policy = static::createPolicy($user, static::$dm, $phone);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token');
        $this->assertEquals($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(), $payment->getAmount());
        $this->assertEquals($payment->getAmount() * $policy->getPremium()->getIptRate(), $payment->getIpt());
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals('Success', $payment->getResult());

        $tokens = $user->getPaymentMethod()->getCardTokens();
        $this->assertEquals(1, count($tokens));
        $data = json_decode($tokens['token']);
        $this->assertEquals('3436', $data->cardLastfour);
        $this->assertEquals('1220', $data->endDate);
    }

    public function testJudoReceiptYearly()
    {
        $user = $this->createValidUser(static::generateEmail('judo-receipt-yearly', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token');
        $this->assertEquals($phone->getCurrentPhonePrice()->getYearlyPremiumPrice(), $payment->getAmount());
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals($policy->getId(), $payment->getReference());
        $this->assertEquals('Success', $payment->getResult());
    }

    /**
     * @expectedException AppBundle\Exception\InvalidPremiumException
     */
    public function testJudoReceiptPaymentDiffException()
    {
        $user = $this->createValidUser(static::generateEmail('judo-receipt-exception', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            '1.01',
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token');
    }

    /**
     * @expectedException AppBundle\Exception\PaymentDeclinedException
     */
    public function testJudoReceiptPaymentDeclinedException()
    {
        $user = $this->createValidUser(static::generateEmail('judo-receipt-declined-exception', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

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
        $payment = self::$judopay->validateReceipt($policy, $receiptId, 'token');
    }

    public function testJudoAdd()
    {
        $user = $this->createValidUser(static::generateEmail('judo-add', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

        $receiptId = self::$judopay->testPay(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );
        self::$judopay->add($policy, $receiptId, 'ctoken', 'token');

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, strlen($policy->getPolicyNumber()));
    }

    public function testJudoScheduledPayment()
    {
        $user = $this->createValidUser(static::generateEmail('judo-scheduled', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );
        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, strlen($policy->getPolicyNumber()));

        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $scheduledPayment = $policy->getScheduledPayments()[0];

        self::$judopay->scheduledPayment($scheduledPayment);
    }

    public function testProcessTokenPayResult()
    {
        $user = $this->createValidUser(static::generateEmail('judo-process-token', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

        $details = self::$judopay->testPayDetails(
            $user,
            $policy->getId(),
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );
        // @codingStandardsIgnoreStart
        self::$judopay->add(
            $policy,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd

        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertGreaterThan(5, strlen($policy->getPolicyNumber()));
        $this->assertEquals(11, count($policy->getScheduledPayments()));

        $scheduledPayment = $policy->getNextScheduledPayment();
        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_SUCCESS);

        self::$judopay->processTokenPayResult($scheduledPayment, $payment);
        $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertEquals(11, count($policy->getScheduledPayments()));

        // Payment failures should be rescheduled
        $initialScheduledPayment = $policy->getNextScheduledPayment();
        for ($i = 1; $i <= 4; $i++) {
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

            self::$judopay->processTokenPayResult($scheduledPayment, $payment, clone $scheduledPayment->getScheduled());
            $this->assertEquals(ScheduledPayment::STATUS_FAILED, $scheduledPayment->getStatus());
            $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());
            $this->assertEquals(11 + $i, count($policy->getScheduledPayments()));
        }

        // A further failed payment should not add another scheduled payment
        $scheduledPayment = $policy->getNextScheduledPayment();
        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_DECLINED);

        self::$judopay->processTokenPayResult($scheduledPayment, $payment, clone $scheduledPayment->getScheduled());
        $this->assertEquals(ScheduledPayment::STATUS_FAILED, $scheduledPayment->getStatus());
        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());
        // 11 + 4
        $this->assertEquals(15, count($policy->getScheduledPayments()));
    }
}
