<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\Salva;
use AppBundle\Document\Address;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\User;
use AppBundle\Exception\CommissionException;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\CheckoutService;
use AppBundle\Service\PolicyService;
use AppBundle\Tests\UserClassTrait;
use Doctrine\ODM\MongoDB\DocumentManager;
use phpDocumentor\Reflection\Types\Integer;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group unit
 */
class PaymentTest extends WebTestCase
{
    use UserClassTrait;
    use DateTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var CheckoutService */
    protected static $checkout;
    /** @var UserRepository */
    protected static $userRepo;
    /** @var User */
    protected static $user;
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
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');
        /** @var CheckoutService $checkout */
        $checkout = self::$container->get('app.checkout');
        self::$checkout = $checkout;
        self::$user = self::createValidUser(
            self::generateEmail(
                'cancelledpaymentstestuser',
                self::returnSelf(),
                true
            )
        );
    }

    public function tearDown()
    {
    }

    private static function createValidUser($email)
    {
        try {
            /** @var User $user */
            $user = static::createUser(self::$userManager, $email, 'foo');
        } catch (\Exception $e) {
            var_dump("Valid user could not be created - ");
            var_dump($e->getMessage());
        }

        try {
            $mobile = static::generateRandomMobile();
        } catch (\Exception $e) {
            var_dump("Could not create random mobile - ");
            var_dump($e->getMessage());
        }

        $user->setFirstName('foo');
        $user->setLastName('bar');
        $user->setMobileNumber($mobile);
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

    public function testCalculatePremium()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2016-05-01');
        $premium = $phonePrice->createPremium(null, $date);

        $phonePolicy = self::initPolicy(
            self::$user,
            self::$dm,
            self::getRandomPhone(self::$dm)
        );

        $phonePolicy->setPremium($premium);

        $payment = new CheckoutPayment();
        $payment->setAmount(5);
        $payment->setPolicy($phonePolicy);
        $payment->calculateSplit();
        $this->assertEquals(4.57, $this->toTwoDp($payment->getGwp()));
        $this->assertEquals(0.43, $this->toTwoDp($payment->getIpt()));
    }

    public function testTotalCommission()
    {
        $payment = new CheckoutPayment();

        // yearly
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::YEARLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::YEARLY_BROKER_COMMISSION, $payment->getBrokerCommission());

        // monthly
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());

        // final month
        $payment->setTotalCommission(Salva::FINAL_MONTHLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::FINAL_MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::FINAL_MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());

        // partial
        $payment->setTotalCommission(0.94);
        $this->assertEquals(0.88, $payment->getCoverholderCommission());
        $this->assertEquals(0.06, $payment->getBrokerCommission());
    }

    /**
     * @expectedException \Exception
     */
    public function testOverwriteSuccess()
    {
        $payment = new CheckoutPayment();
        $this->assertFalse($payment->hasSuccess());
        $payment->setSuccess(true);
        $this->assertTrue($payment->hasSuccess());
        $payment->setSuccess(false);
    }

    public function testSetCommissionMonthly()
    {
        $policy = self::initPolicy(
            self::$user,
            self::$dm,
            self::getRandomPhone(self::$dm)
        );

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        for ($i = 0; $i < 11; $i++) {
            $payment = new CheckoutPayment();
            $payment->setAmount(6);
            $policy->addPayment($payment);
            $payment->setCommission();

            // monthly
            $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
            $this->assertEquals(Salva::MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
            $this->assertEquals(Salva::MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());
        }

        $payment = new CheckoutPayment();
        $payment->setAmount(6);
        $policy->addPayment($payment);
        $payment->setCommission();

        // final month
        $payment->setTotalCommission(Salva::FINAL_MONTHLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::FINAL_MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::FINAL_MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());
    }

    public function testSetCommissionYearly()
    {
        $policy = self::initPolicy(
            self::$user,
            self::$dm,
            self::getRandomPhone(self::$dm)
        );

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $payment = new CheckoutPayment();
        $payment->setAmount(6 * 12);
        $policy->addPayment($payment);
        $payment->setCommission();

        // yearly
        $this->assertEquals(Salva::YEARLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::YEARLY_BROKER_COMMISSION, $payment->getBrokerCommission());
    }

    /**
     * @group blake
     * @expectedException \AppBundle\Exception\CommissionException
     */
    public function testSetCommissionRemainderFailsWithFalse()
    {
        $policy = self::initPolicy(
            self::$user,
            self::$dm,
            self::getRandomPhone(self::$dm)
        );

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $payment = new CheckoutPayment();
        $payment->setAmount($premium->getMonthlyPremiumPrice() * 0.5);

        $policy->addPayment($payment);

        $payment->setCommission(false);
    }

    /**
     * @group blake
     */
    public function testSetCommissionRemainderDoesNotFailWithTrue()
    {
        $now = new \DateTime();

        $policy = self::initPolicy(
            self::$user,
            self::$dm,
            self::getRandomPhone(self::$dm),
            $now,
            false,
            true,
            true
        );

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $payment = new CheckoutPayment();
        $payment->setAmount($premium->getMonthlyPremiumPrice() * 0.5);

        $policy->addPayment($payment);

        $payment->setCommission(true);

        $commission = $payment->getTotalCommission();
        $this->assertGreaterThan(0, $commission);
        self::assertLessThan(Salva::MONTHLY_TOTAL_COMMISSION, $commission);
    }

    /**
     * @expectedException \Exception
     */
    public function testSetCommissionUnknown()
    {
        $policy = self::initPolicy(
            self::$user,
            self::$dm,
            self::getRandomPhone(self::$dm)
        );

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $payment = new CheckoutPayment();
        $payment->setAmount(2);
        $policy->addPayment($payment);
        $payment->setCommission();
    }

    public function testTimezone()
    {
        $payments = [];
        $payment1 = new CheckoutPayment();
        $payment1->setDate(new \DateTime('2018-04-01 00:00', new \DateTimeZone('UTC')));
        $payment1->setAmount(1);
        $payments[] = $payment1;

        $payment2 = new CheckoutPayment();
        $payment2->setDate(new \DateTime('2018-04-01 00:00', new \DateTimeZone('Europe/London')));
        $payment2->setAmount(2);
        $payments[] = $payment2;

        $daily = Payment::dailyPayments($payments, false, CheckoutPayment::class, new \DateTimeZone('UTC'));
        $this->assertEquals(1, $daily[1]);

        $daily = Payment::dailyPayments($payments, false, CheckoutPayment::class);
        $this->assertEquals(3, $daily[1]);
    }
}
