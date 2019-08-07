<?php
/**
 * Copyright (c) So-Sure 2019.
 * @author Blake Payne <blake@so-sure.com>
 */
namespace AppBundle\Tests\Document;

use AppBundle\Document\Address;
use AppBundle\Document\BankAccount;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\User;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\CheckoutService;
use AppBundle\Service\PolicyService;
use AppBundle\Tests\UserClassTrait;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BacsRefundTest extends WebTestCase
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
                'remainder-uneven-test',
                self::returnSelf(),
                true
            )
        );
        self::$phone = static::getRandomPhone(static::$dm);

        $pastPolicy = date_sub(new \DateTime(), new \DateInterval("P1M"));
        $billing = date_sub(new \DateTime(), new \DateInterval("P9D"));

        self::$policy = static::initPolicy(
            self::$user,
            static::$dm,
            self::$phone,
            $pastPolicy,
            true,
            true,
            true,
            null,
            $billing
        );
        self::$policy->setPremiumInstallments(12);
        self::$policy->setPaymentMethod(new BacsPaymentMethod());
        self::$policy->setStatus('active');
        self::$dm->flush();
    }

    private static function createValidUser($email)
    {
        $user = static::createUser(self::$userManager, $email, 'foo');
        $mobile = static::generateRandomMobile();

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

    public function testApproveBacsRefundNegativeCommission()
    {
        $eightDaysBack = date_sub(new \DateTime(), new \DateInterval("P8D"));

        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_PENDING);

        $bacs = new BacsPayment();
        $bankAccount = new BankAccount();
        if (self::$policy->getBacsPaymentMethod()) {
            self::$policy->getBacsPaymentMethod()->setBankAccount($bankAccount);
        }

        $bacs->setAmount(-6.00);
        $bacs->submit(new \DateTime());
        $bacs->setStatus(BacsPayment::STATUS_GENERATED);
        $bacs->setScheduledPayment($scheduledPayment);
        $bacs->setDate($eightDaysBack);
        $scheduledPayment->setPayment($bacs);
        $scheduledPayment->setScheduled($eightDaysBack);
        self::$dm->persist($scheduledPayment);

        self::$policy->addPayment($bacs);
        self::$dm->flush();

        $bacs->approve($eightDaysBack, true);
        self::$dm->flush();

        $this->assertEquals(Bacs::MANDATE_SUCCESS, $bacs->getStatus());
        $this->assertTrue($bacs->isSuccess());
        $this->assertNotNull($bacs->getScheduledPayment());
        /** @var Payment $refund */
        if ($bacs->getScheduledPayment()) {
            $refund = $bacs->getScheduledPayment()->getPayment();
            self::assertLessThan(0, $refund->getTotalCommission());
        }
    }
}
