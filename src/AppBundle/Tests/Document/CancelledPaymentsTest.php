<?php
/**
 * Copyright (c) So-Sure 2019.
 * @author $user
 */

namespace AppBundle\Tests\Document;

use AppBundle\Classes\Salva;
use AppBundle\Document\Address;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\User;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Service\CheckoutService;
use AppBundle\Service\PolicyService;
use AppBundle\Tests\UserClassTrait;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CancelledPaymentsTest extends WebTestCase
{
    use UserClassTrait;
    use DateTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var CheckoutService */
    protected static $checkout;
    protected static $userRepo;

    /** @var User */
    protected static $user;

    /** @var Phone */
    protected static $phone;

    /** @var Policy */
    protected static $policy;

    /** @var PolicyService */
    protected static $policyService;

    public static $CHECKOUT_TEST_CARD_NUM = '4242 4242 4242 4242';
    public static $CHECKOUT_TEST_CARD_LAST_FOUR = '4242';
    public static $CHECKOUT_TEST_CARD_EXP = '01/99';
    public static $CHECKOUT_TEST_CARD_EXP_DATE = '0199';
    public static $CHECKOUT_TEST_CARD_PIN = '100';
    public static $CHECKOUT_TEST_CARD_NAME = 'Visa **** 4242 (Exp: 0199)';
    public static $CHECKOUT_TEST_CARD_TYPE = 'Visa';

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

        self::$phone = static::getRandomPhone(static::$dm);

        self::$policy = static::initPolicy(
            self::$user,
            static::$dm,
            self::$phone,
            null,
            false,
            true
        );
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

    public function testAddPaymentToPolicy()
    {
        $startDate = $this->addDays(new \DateTime(), -40);
        $failDate = $this->addDays(new \DateTime(), -4);
        $rescheduleDate = $this->addDays(new \DateTime(), 5);
        $scheduleDate = $this->addDays(new \DateTime(), 30);

        self::$policy->setStatus(\AppBundle\Document\PhonePolicy::STATUS_UNPAID);

        self::$policyService->setDispatcher(null);
        static::$policyService->create(self::$policy, $startDate);

        self::setCheckoutPaymentMethodForPolicy(self::$policy);
        self::$policy->setPremiumInstallments(12);

        self::$policyService->generateScheduledPayments(self::$policy, $startDate);

        self::$dm->flush();

        $this->assertGreaterThan(0, $this->getOldUnpaid());
        $pay = self::$checkout->testPay(
            self::$policy,
            self::$policy->getId(),
            self::$phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$CHECKOUT_TEST_CARD_NUM,
            self::$CHECKOUT_TEST_CARD_EXP,
            self::$CHECKOUT_TEST_CARD_PIN
        );
        self::$checkout->add(
            self::$policy,
            $pay,
            'web',
            $failDate
        );
        self::$policy = self::$dm->merge(self::$policy);
        $this->assertEquals(0, $this->getOldUnpaid());
    }

    public function getOldUnpaid()
    {
        $last = new \DateTime();
        if (self::$policy->getLastSuccessfulUserPaymentCredit()) {
            /** @var \AppBundle\Repository\ScheduledPaymentRepository $spr */
            $last = self::$policy->getLastSuccessfulUserPaymentCredit()->getDate();
        }
        $spr = self::$dm->getRepository(ScheduledPayment::class);
        $oldUnpaid = $spr->getPastScheduledWithNoStatusUpdate(self::$policy, $last);
        return $oldUnpaid->count();
    }
}
