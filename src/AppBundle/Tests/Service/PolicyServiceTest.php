<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\BankAccount;
use AppBundle\Document\CustomerCompany;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Payment\PolicyDiscountPayment;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Exception\GeoRestrictedException;
use AppBundle\Exception\InvalidUserDetailsException;
use AppBundle\Exception\DuplicateImeiException;
use AppBundle\Service\PaymentService;
use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Cashback;
use AppBundle\Document\Claim;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\SCode;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Connection\RenewalConnection;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;
use AppBundle\Service\InvitationService;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\ValidationException;
use AppBundle\Classes\Salva;
use AppBundle\Classes\SoSure;
use AppBundle\Service\SalvaExportService;
use AppBundle\Document\LogEntry;
use AppBundle\Tests\Create;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\LogEntryRepository;
use Symfony\Component\Validator\Constraints\Date;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Service\\PolicyServiceTest
 */
class PolicyServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use \AppBundle\Document\DateTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $policyRepo;
    protected static $judopay;

    /** @var PaymentService */
    protected static $paymentService;

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
        self::$policyRepo = self::$dm->getRepository(Policy::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        /** @var PolicyService $policyService */
        $policyService = self::$container->get('app.policy');
        self::$policyService = $policyService;
        self::$policyService->setDispatcher(null);

        self::$judopay = self::$container->get('app.judopay');

        /** @var PaymentService $paymentService */
        $paymentService = self::$container->get('app.payment');
        self::$paymentService = $paymentService;

        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
    }

    public function setUp()
    {
        parent::setUp();
        set_time_limit(1600);
    }

    public function tearDown()
    {
    }

    public function testCancelPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('cancel', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, false, true);
        static::$policyService->create($policy);
        static::$policyService->cancel($policy, Policy::CANCELLED_USER_REQUESTED);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(Policy::STATUS_CANCELLED, $updatedPolicy->getStatus());
    }

    /**
     * @expectedException \Exception
     */
    public function testCancelInProgressPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCancelInProgressPolicy', $this, true),
            'bar',
            null,
            static::$dm
        );

        /* Create a policy that has a null status */
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            null,
            false,
            false
        );

        $this->assertNull($policy->getStatus());
        static::$policyService->cancel($policy, Policy::CANCELLED_USER_REQUESTED);
    }

    public function testCreatePolicyHasLaunchPromoCode()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCreatePolicyHasLaunchPromoCode', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-10-01'),
            true
        );
        static::$policyService->create($policy, new \DateTime('2016-10-01'));

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(Policy::PROMO_LAUNCH, $policy->getPromoCode());
    }

    /**
     * Not applicable now
    public function testCreatePolicyHasLaunchNovPromoCode()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCreatePolicyHasLaunchNovPromoCode', $this),
            'bar',
            null,
            static::$dm
        );
        $user->setPreLaunch(true);
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setDispatcher(null);
        static::$policyService->create($policy, new \DateTime('2016-11-01'));

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(Policy::PROMO_LAUNCH_FREE_NOV, $policy->getPromoCode());
    }
    */

    /**
     */
    public function testCreatePolicyHasNovPromoCode()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCreatePolicyHasNovPromoCode', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-11-01'),
            true
        );
        static::$policyService->setDispatcher(null);
        static::$policyService->create($policy, new \DateTime('2016-11-01'));

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(Policy::PROMO_FREE_NOV, $policy->getPromoCode());
    }

    /**
     */
    public function testCreatePolicyHasDec2016PromoCode()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCreatePolicyHasDec2016PromoCode', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-12-01'),
            true
        );
        static::$policyService->setDispatcher(null);
        static::$policyService->create($policy, new \DateTime('2016-12-01'));

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(Policy::PROMO_FREE_DEC_2016, $policy->getPromoCode());
    }

    /**
     * TODO - generate 1000 policies or adjust query somehow
    public function testCreatePolicyHasNoPromoCode()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCreatePolicyHasNoPromoCode', $this),
            'bar',
            null,
            static::$dm
        );
        $user->setPreLaunch(true);
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->create($policy, new \DateTime('2016-12-01'));

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertNull($policy->getPromoCode());
    }
    */

    /**
     */
    public function testCreatePolicyPolicyNumber()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('create-policyNumber', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertTrue($updatedPolicy->isPolicy(), 'Policy must have a status');
        $this->assertTrue($updatedPolicy->isValidPolicy(), 'Policy must be valid');
        $this->assertTrue(
            mb_stripos($updatedPolicy->getPolicyNumber(), 'Mob/') !== false,
            'Policy number must contain Mob'
        );
    }

    public function testCreatePolicySoSurePolicyNumber()
    {
        $user = static::createUser(
            static::$userManager,
            self::generateEmail(
                'create-policyNumber',
                $this,
                true
            ),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        /** @var Policy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertTrue($updatedPolicy->isPolicy(), 'Policy must have a status');
        $this->assertTrue($updatedPolicy->isValidPolicy());
        $this->assertFalse(mb_stripos($updatedPolicy->getPolicyNumber(), 'INVALID/') !== false);
    }

    public function testCreatePolicyDuplicateCreate()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('create-dup', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertTrue($updatedPolicy->isPolicy(), 'Policy must have a status');
        $this->assertTrue($updatedPolicy->isValidPolicy(), 'Policy must be valid');
        $this->assertTrue(
            mb_stripos($updatedPolicy->getPolicyNumber(), 'Mob/') !== false,
            'Policy number must contain Mob'
        );
        $this->assertEquals(new \DateTime('2016-01-01 03:00'), $updatedPolicy->getStart());

        // Needs to be prod for a valid policy number, or create will affect policy times
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-02-01'));
        static::$policyService->setEnvironment('test');

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertTrue($updatedPolicy->isPolicy(), 'Policy must have a status');
        $this->assertTrue($updatedPolicy->isValidPolicy(), 'Policy must be valid');
        $this->assertTrue(
            mb_stripos($updatedPolicy->getPolicyNumber(), 'Mob/') !== false,
            'Policy number must contain Mob'
        );
        $this->assertEquals(new \DateTime('2016-01-01 03:00'), $updatedPolicy->getStart());
    }

    public function testCreatePolicyWithoutBillingSetBillingAt3am()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('no-billing-3am', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true,
            true
        );
        $this->assertEquals("03:00:00", $policy->getBilling()->format("H:i:s"));
    }

    public function testCreatePolicyBillingSetBillingAt3am()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('billing-3am', $this, true),
            'bar',
            null,
            static::$dm
        );
        $billing = date_add(new \DateTime(), new \DateInterval("P9D"));
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true,
            true,
            true,
            null,
            $billing
        );
        $this->assertEquals("03:00:00", $policy->getBilling()->format("H:i:s"));
    }

    /**
     * @expectedException AppBundle\Exception\InvalidPremiumException
     * @group schedule
     */
    public function testGenerateScheduledPaymentsInvalidAmount()
    {
        $user = static::createUser(
            static::$userManager,
            self::generateEmail('scheduled-invalidamount', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));

        $payment = new JudoPayment();
        $payment->setAmount(0.01);
        $policy->addPayment($payment);
        static::$policyService->create($policy);
    }

    /**
     * @group schedule
     */
    public function testGenerateScheduledPaymentsMonthlyPayments()
    {
        $user = static::createUser(
            static::$userManager,
            self::generateEmail('scheduled-monthly', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));

        $payment = new BacsPayment();
        $payment->setAmount($policy->getPhone()->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setSuccess(true);
        $policy->addPayment($payment);

        static::$policyService->create($policy);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(11, count($updatedPolicy->getScheduledPayments()));
    }

    /**
     * @group schedule
     */
    public function testGenerateScheduledPaymentsMonthlyPaymentsDates()
    {
        $dates = [
            28 => 28,
            29 => 28,
            31 => 28,
            1 => 1,
        ];
        foreach ($dates as $actualDay => $expectedDay) {
            foreach ([1, 7] as $month) {
                $user = static::createUser(
                    static::$userManager,
                    static::generateEmail(sprintf('scheduled-monthly-%d-%d', $month, $actualDay), $this, true),
                    'bar',
                    null,
                    static::$dm
                );
                $date = (new \DateTime())->add(new \DateInterval(sprintf('P%dM%dD', $month, $actualDay)));
                $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), $date);
                $phone = $policy->getPhone();

                $payment = new BacsPayment();
                $payment->setAmount($phone->getCurrentPhonePrice($date)->getMonthlyPremiumPrice(null, $date));
                $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
                $payment->setDate($date);
                $payment->setSuccess(true);
                $policy->addPayment($payment);

                static::$policyService->create($policy, $date);
                $policy->setStatus(Policy::STATUS_ACTIVE);
                static::$dm->flush();
                /*
                print 'B1----------' . PHP_EOL;
                print_r($policy->getBilling());
                print 'B2----------' . PHP_EOL;
                */

                $updatedPolicy = static::$policyRepo->find($policy->getId());
                $this->assertEquals(11, count($updatedPolicy->getScheduledPayments()));
                for ($i = 0; $i < 11; $i++) {
                    $scheduledDate = $updatedPolicy->getScheduledPayments()[$i]->getScheduled();
                    //$this->assertEquals($expectedDay, $scheduledDate->format('d'));
                    $this->assertTrue($scheduledDate->diff($policy->getStart())->days >= 27);
                }

                $this->assertTrue($policy->arePolicyScheduledPaymentsCorrect(true));
            }
        }
    }

    /**
     * @group schedule
     */
    public function testGenerateScheduledPaymentsMonthlyPaymentsDatesTimezone()
    {
        $dates = [
            28 => 28,
            29 => 28,
            31 => 1,
            1 => 1,
        ];
        foreach ($dates as $actualDay => $expectedDay) {
            foreach ([1, 7] as $month) {
                $user = static::createUser(
                    static::$userManager,
                    static::generateEmail(sprintf('scheduled-monthly-tz-%d-%d', $month, $actualDay), $this, true),
                    'bar',
                    null,
                    static::$dm
                );
                $date = (new \DateTime())->add(new \DateInterval(sprintf('P%dM%dD', $month, $actualDay)));
                $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), $date);
                /*
                print 'I1----------' . PHP_EOL;
                print_r($date);
                print 'I2----------' . PHP_EOL;
                */
                $phone = $policy->getPhone();

                $payment = new BacsPayment();
                $payment->setAmount($phone->getCurrentPhonePrice($date)->getMonthlyPremiumPrice(null, $date));
                $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
                $payment->setDate($date);
                $payment->setSuccess(true);
                $policy->addPayment($payment);

                static::$policyService->create($policy, $date);
                $policy->setStatus(Policy::STATUS_ACTIVE);
                static::$dm->flush();
                /*
                print 'B1----------' . PHP_EOL;
                print_r($policy->getBilling());
                print 'B2----------' . PHP_EOL;
                */

                $updatedPolicy = static::$policyRepo->find($policy->getId());
                $this->assertEquals(11, count($updatedPolicy->getScheduledPayments()));
                for ($i = 0; $i < 11; $i++) {
                    $scheduledDate = $updatedPolicy->getScheduledPayments()[$i]->getScheduled();
                    //$this->assertEquals($expectedDay, $scheduledDate->format('d'));
                    $this->assertTrue($scheduledDate->diff($policy->getStart())->days >= 27);
                }

                $this->assertTrue($policy->arePolicyScheduledPaymentsCorrect(true));
            }
        }
    }

    /**
     * @group schedule
     */
    public function testAreScheduledPaymentsCorrectBacs()
    {
        $date = new \DateTime();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testAreScheduledPaymentsCorrectBacs', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), $date);
        static::setBacsPaymentMethodForPolicy($policy);
        $phone = $policy->getPhone();
        static::$paymentService->confirmBacs($policy, $policy->getPolicyOrUserBacsPaymentMethod(), $date);
        $policy->getPolicyOrUserBacsBankAccount()->setInitialPaymentSubmissionDate($date);

        $payment = new BacsPayment();
        $payment->setAmount($phone->getCurrentPhonePrice($date)->getMonthlyPremiumPrice(null, $date));
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setDate($date);
        $payment->setSuccess(true);
        $policy->addPayment($payment);

        static::$policyService->create($policy, $date);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $scheduledPayment = $policy->getScheduledPayments()[1];
        $scheduledPayment->setScheduled($policy->getPolicyOrUserBacsBankAccount()->getInitialNotificationDate());
        static::$dm->flush();

        $this->assertNotEquals(
            $date,
            $policy->getPolicyOrUserBacsBankAccount()->getInitialNotificationDate(),
            '',
            1
        );

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertTrue($policy->arePolicyScheduledPaymentsCorrect(true));
    }

    /**
     * @expectedException AppBundle\Exception\InvalidPremiumException
     * @group schedule
     */
    public function testGenerateScheduledPaymentsFailedMonthlyPayments()
    {
        $user = static::createUser(
            static::$userManager,
            self::generateEmail('scheduled-failed-monthly', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));

        $payment = new JudoPayment();
        $payment->setAmount($policy->getPhone()->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $policy->addPayment($payment);

        static::$policyService->create($policy);
    }

    /**
     * @group schedule
     */
    public function testGenerateScheduledPaymentsYearlyPayment()
    {
        $user = static::createUser(
            static::$userManager,
            self::generateEmail('scheduled-yearly', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));

        $payment = new BacsPayment();
        $payment->setAmount($policy->getPhone()->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setSuccess(true);
        $policy->addPayment($payment);

        static::$policyService->create($policy);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(0, count($updatedPolicy->getScheduledPayments()));
    }

    /**
     * @expectedException \Exception
     * @group schedule
     */
    public function testGenerateScheduledPaymentsMissingPayment()
    {
        $user = static::createUser(
            static::$userManager,
            self::generateEmail('scheduled-missing', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));
        static::$policyService->create($policy);
    }

    /**
     * Tests that when a policy is created with no other payments, a full 12 payments are added rather than the
     * customary 11.
     * @group schedule
     */
    public function testGenerateScheduledPaymentsNoInitial()
    {
        $user = static::createUser(
            static::$userManager,
            self::generateEmail('scheduled-monthly-renewal', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));
        $policy->setPremiumInstallments(12);
        $date = new \DateTime();
        static::$policyService->create(
            $policy,
            $date,
            true,
            null,
            null,
            $date
        );
        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(12, count($updatedPolicy->getScheduledPayments()));
    }

    /**
     * Tests that when a policy is created with no other payments, a full 12 payments are added rather than the
     * customary 11, and make sure it works on the 31st.
     * @group schedule
     */
    public function testGenerateScheduledPaymentsNoInitialThirtyFirst()
    {
        $user = static::createUser(
            static::$userManager,
            self::generateEmail('scheduled-monthly-renewal-31st', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));
        $policy->setPremiumInstallments(12);
        $date = new \DateTime('2019-08-31');
        $now = new \DateTime();
        // We can only schedule payments in the future or within 4 days ago, so we make the date a future date.
        while ($date < $now) {
            $date->add(new \DateInterval("P1Y"));
        }
        static::$policyService->create(
            $policy,
            $date,
            true,
            null,
            null,
            $date
        );
        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(12, count($updatedPolicy->getScheduledPayments()));
    }

    public function testSalvaCancelSimple()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-cancel', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            static::$phone,
            new \DateTime('2016-01-01'),
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');

        static::addPayment($policy, $policy->getPremium()->getMonthlyPremiumPrice(), Salva::MONTHLY_TOTAL_COMMISSION);

        // 61 days (366/12 * 2)
        static::$policyService->cancel(
            $policy,
            PhonePolicy::CANCELLED_ACTUAL_FRAUD,
            false,
            new \DateTime('2016-02-02')
        );
        // 6.99 / month
        $this->assertEquals(13.98, $policy->getTotalPremiumPrice());
        // 6.38 / month rough - (6.99 * 12 / (1.095)) * 61 / 366  = 12.77
        $this->assertEquals(12.77, $policy->getUsedGwp());
        $this->assertEquals(12.77, $policy->getTotalGwp());
        // 0.61 / month rough - 6.38 * 12 * 0.095 * 61/366 = 1.21
        $this->assertEquals(1.21, $policy->getTotalIpt());
        // 0.89 / month rough - 10.72 * 61/366 = 1.78
        $this->assertEquals(1.79, $policy->getTotalBrokerFee());
    }

    public function testAdjustScheduledPaymentsOk()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testAdjustScheduledPayments', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-10-01'),
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-10-01'));
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        // Initial payment applied - nothing to adjust
        $this->assertNull(self::$policyService->adjustScheduledPayments($policy));
        $this->assertEquals(0, count($policy->getAllScheduledPayments(ScheduledPayment::STATUS_CANCELLED)));
    }

    public function testAdjustScheduledPaymentsAdditionalPayment()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testAdjustScheduledPaymentsAdditionalPayment', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-10-01'),
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-10-01'));
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        // additional payment should require an adjustment a scheduledpayment cancellation
        static::addPayment($policy, $policy->getPremium()->getMonthlyPremiumPrice());

        // 1 scheduled payment should be cancelled to offset the additional payment received
        $this->assertTrue(self::$policyService->adjustScheduledPayments($policy));
        $this->assertEquals(1, count($policy->getAllScheduledPayments(ScheduledPayment::STATUS_CANCELLED)));
    }

    public function testAdjustScheduledPaymentsLaterDate()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testAdjustScheduledPaymentsLaterDate', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-10-01'),
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-10-01'));
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $billingDate = $this->setDayOfMonth($policy->getBilling(), '28');
        $policy->setBilling($billingDate, new \DateTime('2016-10-02'));

        $this->assertNull(self::$policyService->adjustScheduledPayments($policy));
        $scheduledPayments = $policy->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);
        $this->assertEquals(11, count($scheduledPayments));
        $this->assertEquals(28, $scheduledPayments[0]->getScheduledDay());
    }

    public function testPolicyCancelledTooEarly()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyCancelledTooEarly', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2017-01-29'),
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2017-01-29'));
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $timezone = new \DateTimeZone('Europe/London');
        $this->assertEquals(
            new \DateTime('2017-03-30', $timezone),
            $policy->getPolicyExpirationDate()
        );

        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            null,
            new \DateTime('2017-02-28')
        );
        $this->assertEquals(
            new \DateTime('2017-04-27', $timezone),
            $policy->getPolicyExpirationDate()
        );

        // in previous case, payment was on 16/4, which was after the change in billing date
        // and cause the problem. as exception will prevent, no point in testing that case here
        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            null,
            new \DateTime('2017-04-12')
        );
        $this->assertEquals(
            new \DateTime('2017-05-28', $timezone),
            $policy->getPolicyExpirationDate()
        );
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2017-04-20')));

        $billingDate = $this->setDayOfMonth($policy->getBilling(), '15');
        $policy->setBilling($billingDate, new \DateTime('2017-04-20'));

        $this->assertTrue(self::$policyService->adjustScheduledPayments($policy));
        $scheduledPayments = $policy->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);
        $this->assertEquals(15, $scheduledPayments[0]->getScheduledDay());

        $this->assertEquals(
            new \DateTime('2017-05-15', $timezone),
            $policy->getPolicyExpirationDate()
        );
    }

    public function testPolicyYearlyWithDiscountUnpaidExpirationDate()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyYearlyWithDiscountUnpaidExpirationDate', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2017-01-29'),
            false
        );
        $policy->setPremiumInstallments(1);
        $discount = new PolicyDiscountPayment();
        $discount->setAmount(2);
        $discount->setDate(new \DateTime('2017-01-29'));
        $policy->addPayment($discount);
        $policy->getPremium()->setAnnualDiscount($discount->getAmount());

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2017-01-29'));
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $this->assertEquals(
            new \DateTime('2017-02-28 03:00'),
            $policy->getPolicyExpirationDate()
        );
    }

    public function testPolicyCancelledTooEarlyBug()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyCancelledTooEarlyBug', $this, true),
            'bar',
            static::$dm
        );
        $date = new \DateTime('2017-04-15 00:16:00', new \DateTimeZone('Europe/London'));
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $date,
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, $date);
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
        $this->assertEquals(
            new \DateTime('2017-04-15 03:00'),
            $policy->getBilling()
        );

        $timezone = new \DateTimeZone('Europe/London');
        $this->assertEquals(
            new \DateTime('2017-05-15 00:00', $timezone),
            $policy->getNextBillingDate(new \DateTime('2017-04-16'))
        );
        $this->assertEquals(
            new \DateTime('2017-06-15 00:00', $timezone),
            $policy->getNextBillingDate(new \DateTime('2017-06-14'))
        );
        $this->assertEquals(
            $policy->getPremium()->getMonthlyPremiumPrice(),
            $policy->getTotalSuccessfulPayments(new \DateTime('2017-05-15', $timezone))
        );
        $this->assertEquals(
            $policy->getPremium()->getMonthlyPremiumPrice(),
            $policy->getTotalExpectedPaidToDate(new \DateTime('2017-05-15', $timezone))
        );
        $this->assertEquals(0, $policy->getOutstandingPremiumToDate(new \DateTime('2017-05-15', $timezone)));
        $this->assertEquals(
            new \DateTime('2017-06-14', $timezone),
            $policy->getPolicyExpirationDate(new \DateTime('2017-06-15', $timezone))
        );

        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            null,
            new \DateTime('2017-05-22 00:22:00', new \DateTimeZone('Europe/London'))
        );
        $this->assertEquals(
            new \DateTime('2017-07-15', $timezone),
            $policy->getPolicyExpirationDate(new \DateTime('2017-06-15', $timezone))
        );

        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            null,
            new \DateTime('2017-06-15 00:20:00', new \DateTimeZone('Europe/London'))
        );
        $this->assertEquals(
            new \DateTime('2017-08-14', $timezone),
            $policy->getPolicyExpirationDate(new \DateTime('2017-07-15', $timezone))
        );
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2017-06-20')));

        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            null,
            new \DateTime('2017-07-15 00:20:00', new \DateTimeZone('Europe/London'))
        );
        $this->assertEquals(
            new \DateTime('2017-09-14', $timezone),
            $policy->getPolicyExpirationDate(new \DateTime('2017-08-15', $timezone))
        );
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2017-07-20')));

        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            null,
            new \DateTime('2017-08-30 14:52:00', new \DateTimeZone('Europe/London'))
        );
        $this->assertEquals(
            new \DateTime('2017-10-15', $timezone),
            $policy->getPolicyExpirationDate(new \DateTime('2017-09-15', $timezone))
        );
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2017-08-31')));

        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            null,
            new \DateTime('2017-09-15 00:20:00', new \DateTimeZone('Europe/London'))
        );
        $this->assertEquals(
            new \DateTime('2017-11-14', $timezone),
            $policy->getPolicyExpirationDate(new \DateTime('2017-10-15', $timezone))
        );
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2017-10-14')));
        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2017-10-20')));
    }

    /**
     * @expectedException \Exception
     */
    public function testPolicyUnpaidUnableToChangeBilling()
    {
        $pastDue = \DateTime::createFromFormat('U', time());
        $pastDue = $pastDue->sub(new \DateInterval('P35D'));
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyUnpaidUnableToChangeBilling', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $pastDue,
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, $pastDue);
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $this->assertFalse($policy->isPolicyPaidToDate());
        $this->assertNotNull($policy->getBilling());

        $now = \DateTime::createFromFormat('U', time());
        $billingDate = $this->setDayOfMonth($now, '15');
        $policy->setBilling($billingDate);
    }

    public function testHasScheduledPaymentInCurrentMonth()
    {
        $date = \DateTime::createFromFormat('U', time());

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testHasScheduledPaymentInCurrentMonth', $this, true),
            'foo',
            static::$dm
        );

        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $date,
            true,
            true
        );

        $this->assertNotNull($policy->getStatus());
        $this->assertFalse($policy->hasScheduledPaymentInCurrentMonth($date));

        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $scheduledPayment->setScheduled(\DateTime::createFromFormat('U', time()));

        $policy->addScheduledPayment($scheduledPayment);

        $this->assertTrue($policy->hasScheduledPaymentInCurrentMonth($date));
    }

    public function testAdjustScheduledPaymentsEarlierDate()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testAdjustScheduledPaymentsEarlierDate', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-10-28'),
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-10-28'));
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $billingDate = $this->setDayOfMonth($policy->getBilling(), '1');
        $policy->setBilling($billingDate, new \DateTime('2016-11-05'));

        $this->assertNull(self::$policyService->adjustScheduledPayments($policy));
        $scheduledPayments = $policy->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);
        $this->assertEquals(11, count($scheduledPayments));
        $this->assertEquals(1, $scheduledPayments[0]->getScheduledDay());
    }

    public function testUnableToAdjustScheduledPayments()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testUnableToAdjustScheduledPayments', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-10-01'),
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-10-01'));
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        // additional payment with a 1p diff should result in unable to find a scheduled payment to cancel
        static::addPayment($policy, $policy->getPremium()->getMonthlyPremiumPrice()  + 0.01);
        $this->assertFalse(self::$policyService->adjustScheduledPayments($policy));
        $this->assertEquals(0, count($policy->getAllScheduledPayments(ScheduledPayment::STATUS_CANCELLED)));
    }

    public function testSalvaCooloff()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-cooloff', $this, true),
            'bar',
            static::$dm
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, new \DateTime('2016-01-01'));
        static::addJudoPayPayment(self::$judopay, $policy, new \DateTime('2016-01-01'));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');

        static::$policyService->cancel(
            $policy,
            PhonePolicy::CANCELLED_COOLOFF,
            false,
            new \DateTime('2016-01-10')
        );
        $this->assertEquals(0, $policy->getTotalPremiumPrice());
        $this->assertEquals(0, $policy->getTotalGwp());
        $this->assertEquals(0, $policy->getUsedGwp());
        $this->assertEquals(0, $policy->getTotalIpt());
        $this->assertEquals(0, $policy->getTotalBrokerFee());
    }

    public function testSalvaFullPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-full', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');

        for ($i = 1; $i <= 10; $i++) {
            static::addPayment(
                $policy,
                $policy->getPremium()->getMonthlyPremiumPrice()
            );
        }
        static::addPayment($policy, $policy->getPremium()->getMonthlyPremiumPrice());

        $this->assertEquals($policy->getPremium()->getYearlyPremiumPrice(), $policy->getTotalPremiumPrice());
        $this->assertEquals($policy->getPremium()->getYearlyGwp(), $policy->getTotalGwp());
        $this->assertEquals($policy->getPremium()->getYearlyGwp(), $policy->getUsedGwp());
        $this->assertEquals($policy->getPremium()->getYearlyIpt(), $policy->getTotalIpt());
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $policy->getTotalBrokerFee());
    }

    public function testSalvaFullPolicyGwpDiff()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSalvaFullPolicyGwpDiff', $this, true),
            'bar',
            static::$dm
        );
        $phoneRepo = static::$dm->getRepository(Phone::class);
        $phone = $phoneRepo->findOneBy(['devices' => 'D6502']);
        $policy = static::initPolicy($user, static::$dm, $phone, new \DateTime('2016-01-01'), true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');

        for ($i = 1; $i <= 10; $i++) {
            static::addPayment($policy, $policy->getPremium()->getMonthlyPremiumPrice());
        }
        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::FINAL_MONTHLY_TOTAL_COMMISSION
        );
        static::$dm->flush();

        $this->assertEquals($policy->getPremium()->getYearlyPremiumPrice(), $policy->getTotalPremiumPrice());
        $this->assertEquals($policy->getPremium()->getYearlyGwp(), $policy->getTotalGwp());
        $this->assertEquals($policy->getPremium()->getYearlyGwp(), $policy->getUsedGwp());
        $this->assertEquals($policy->getPremium()->getYearlyIpt(), $policy->getTotalIpt());
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $policy->getTotalBrokerFee());
    }

    public function testSalvaPartialPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-partial', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');

        $policy->addPayment(new JudoPayment());

        $payment = new JudoPayment();
        $payment->setAmount('1');

        $this->assertEquals($policy->getPremium()->getYearlyPremiumPrice(), $policy->getTotalPremiumPrice());
        $this->assertEquals($policy->getPremium()->getYearlyGwp(), $policy->getTotalGwp());
        $this->assertEquals($policy->getPremium()->getYearlyIpt(), $policy->getTotalIpt());
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $policy->getTotalBrokerFee());
    }

    public function testScodeUnique()
    {
        $scode = new SCode();
        static::$dm->persist($scode);
        static::$dm->flush();

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('scode', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        $dupSCode = new SCode();
        $dupSCode->setCode($scode->getCode());
        $policy->addSCode($dupSCode);

        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        $this->assertNotEquals($policy->getStandardSCode()->getCode(), $scode->getCode());
    }

    public function testScodeMultiplePolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testScodeMultiplePolicy', $this, true),
            'bar',
            static::$dm
        );
        $policyA = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        $policyB = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        static::$policyService->create($policyA, new \DateTime('2016-01-01'));
        $this->assertNotNull($policyA->getStandardSCode());

        static::$policyService->create($policyB, new \DateTime('2016-01-01'));
        $this->assertNotNull($policyB->getStandardSCode());
        $this->assertEquals(
            $policyA->getStandardSCode()->getCode(),
            $policyB->getStandardSCode()->getCode()
        );
    }

    /**
     * @expectedException \AppBundle\Exception\InvalidUserDetailsException
     */
    public function testValidateUserInValidDetails()
    {
        $user = new User();
        static::$policyService->validateUser($user);
    }

    /**
     * @expectedException \AppBundle\Exception\InvalidUserDetailsException
     */
    public function testValidateUserInValidBillingDetails()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidateUserInValidBillingDetails', $this, true),
            'bar',
            $this->getRandomPhone(static::$dm),
            static::$dm
        );

        static::$policyService->validateUser($user);
    }

    /**
     * @expectedException \AppBundle\Exception\GeoRestrictedException
     */
    public function testValidateUserInValidPostcode()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidateUserInValidPostcode', $this, true),
            'bar',
            $this->getRandomPhone(static::$dm),
            static::$dm
        );

        static::addAddress($user);
        $user->getBillingAddress()->setPostcode('ZZ993CZ');

        static::$policyService->validateUser($user);
    }

    public function testValidateUser()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidateUser', $this, true),
            'bar',
            $this->getRandomPhone(static::$dm),
            static::$dm
        );

        static::addAddress($user);

        static::$policyService->validateUser($user);

        // test is that exception is not thrown
        $this->assertTrue(true);
    }

    public function testValidatePremiumIptRateChange()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('vpreium-rate', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-09-30'),
            true
        );
        $premium = $policy->getPremium();
        $this->assertEquals(0.095, $premium->getIptRate());

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-10-01'));
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedPolicy = $policyRepo->find($policy->getId());

        $updatedPremium = $updatedPolicy->getPremium();
        //\Doctrine\Common\Util\Debug::dump($updatedPremium);
        $this->assertNotEquals($premium, $updatedPremium);
        $this->assertEquals(0.1, $updatedPremium->getIptRate());
    }

    public function testValidatePremiumNormal()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('vpreium-normal', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-09-01'),
            true
        );
        $premium = $policy->getPremium();

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-09-01'));
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedPolicy = $policyRepo->find($policy->getId());

        $this->assertEquals($premium, $updatedPolicy->getPremium());
    }

    public function testPoliciesPendingCancellation()
    {
        $yesterday = \DateTime::createFromFormat('U', time());
        $yesterday = $yesterday->sub(new \DateInterval('P1D'));
        $now = \DateTime::createFromFormat('U', time());
        $tomorrow = \DateTime::createFromFormat('U', time());
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));

        $userFuture = static::createUser(
            static::$userManager,
            static::generateEmail('testPoliciesPendingCancellation-future', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policyFuture = static::initPolicy(
            $userFuture,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            clone $yesterday,
            true
        );
        static::$policyService->create($policyFuture, clone $yesterday);
        $policyFuture->setStatus(Policy::STATUS_ACTIVE);

        $userExpire = static::createUser(
            static::$userManager,
            static::generateEmail('testPoliciesPendingCancellation-expire', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policyExpire = static::initPolicy(
            $userExpire,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            clone $yesterday,
            true
        );
        static::$policyService->create($policyExpire, clone $yesterday);
        $policyExpire->setStatus(Policy::STATUS_ACTIVE);

        $policyFuture->setPendingCancellation(clone $tomorrow);
        $policyExpire->setPendingCancellation(clone $yesterday);
        static::$dm->flush();

        $foundFuture = false;
        $foundExpire = false;
        $policies = static::$policyService->getPoliciesPendingCancellation(false);
        foreach ($policies as $policy) {
            if ($policy->getId() == $policyExpire->getId()) {
                $foundExpire = true;
            } elseif ($policy->getId() == $policyFuture->getId()) {
                $foundFuture = true;
            }
        }
        $this->assertTrue($foundExpire);
        $this->assertFalse($foundFuture);

        $foundFuture = false;
        $foundExpire = false;
        $policies = static::$policyService->getPoliciesPendingCancellation(true);
        foreach ($policies as $policy) {
            if ($policy->getId() == $policyExpire->getId()) {
                $foundExpire = true;
            } elseif ($policy->getId() == $policyFuture->getId()) {
                $foundFuture = true;
            }
        }
        $this->assertTrue($foundExpire);
        $this->assertTrue($foundFuture);

        static::$policyService->setDispatcher(null);
        $policies = static::$policyService->cancelPoliciesPendingCancellation();
        $foundFuture = false;
        $foundExpire = false;
        foreach ($policies as $id => $number) {
            if ($id == $policyExpire->getId()) {
                $foundExpire = true;
            } elseif ($id == $policyFuture->getId()) {
                $foundFuture = true;
            }
        }
        $this->assertTrue($foundExpire);
        $this->assertFalse($foundFuture);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedPolicy = $policyRepo->find($policyExpire->getId());
        $this->assertEquals(Policy::STATUS_CANCELLED, $updatedPolicy->getStatus());
    }

    public function testCreatePolicyBacs()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCreatePolicyBacs', $this, true),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm)
        );

        $bacs = new BacsPayment();
        $bacs->setManual(true);
        $bacs->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacs->setSuccess(true);
        $bacs->setAmount($policy->getPhone()->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $policy->addPayment($bacs);
        static::$dm->flush();

        static::$policyService->setDispatcher(null);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, null, true);
        static::$policyService->setEnvironment('test');

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(0, count($updatedPolicy->getScheduledPayments()));
        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertEquals(Policy::PLAN_YEARLY, $policy->getPremiumPlan());
    }

    public function testPolicyExpire()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyExpire', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        static::$policyService->expire($policy, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policy->getStatus());
    }

    public function testPolicyRenew()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyRenew', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-06-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $policy->getStart()->getTimeZone());

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2017-05-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        static::$policyService->renew($policy, 12, null, false, new \DateTime('2017-05-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicy->getStatus());
        $this->assertNull($policy->getCashback());
        $this->assertEquals(
            new \DateTime('2017-06-01 03:00'),
            $renewalPolicy->getStart()
        );
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $renewalPolicy->getStart()->getTimeZone());
    }

    public function testPolicyPendingRenewalCompany()
    {
        $company = new CustomerCompany();

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyPendingRenewalCompany', $this, true),
            'bar',
            static::$dm
        );

        static::$dm->persist($company);

        $user->setCompany($company);

        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-06-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $policy->getStart()->getTimeZone());

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2017-05-15')
        );
    }

    public function testPolicyRenewMultiPay()
    {
        $payer = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyRenewMultiPay-payer', $this, true),
            'bar',
            static::$dm
        );
        $payee = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyRenewMultiPay-payee', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $payee,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        $payer->addPayerPolicy($policy);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-06-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $policy->getStart()->getTimeZone());

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2017-05-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        static::$policyService->renew($policy, 12, null, false, new \DateTime('2017-05-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicy->getStatus());
        $this->assertNull($policy->getCashback());
        $this->assertEquals($payer->getId(), $renewalPolicy->getPayer()->getId());
    }

    public function testPolicyAutoRenewUnpaid()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyAutoRenewUnpaid', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-06-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $policy->getStart()->getTimeZone());

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2017-05-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        static::$policyService->autoRenew($policy, new \DateTime('2017-06-01'));
        $this->assertEquals(Policy::STATUS_UNRENEWED, $renewalPolicy->getStatus());
        $this->assertNull($policy->getCashback());
        $this->assertNull($renewalPolicy->getStart());
    }

    public function testPolicyAutoRenewWhenCancelled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyAutoRenewWhenCancelled', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-06-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $policy->getStart()->getTimeZone());

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2017-05-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        $this->assertFalse(static::$policyService->autoRenew($policy, new \DateTime('2017-06-01')));
        $this->assertEquals(Policy::STATUS_UNRENEWED, $renewalPolicy->getStatus());
        $this->assertNull($policy->getCashback());
        $this->assertNull($renewalPolicy->getStart());
    }

    public function testPolicyAutoRenewPaid()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyAutoRenewPaid', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-06-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $policy->getStart()->getTimeZone());

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2017-05-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        self::addPayment(
            $policy,
            $policy->getPremium(new \DateTime('2017-01-01'))->getMonthlyPremiumPrice() * 11,
            Salva::MONTHLY_TOTAL_COMMISSION * 10 + Salva::FINAL_MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2017-01-01')
        );

        static::$policyService->autoRenew($policy, new \DateTime('2017-06-01'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicy->getStatus());
        $this->assertNull($policy->getCashback());
        $this->assertEquals(
            new \DateTime('2017-06-01 03:00'),
            $renewalPolicy->getStart()
        );
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $renewalPolicy->getStart()->getTimeZone());
    }

    public function testPolicyRepurchase()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyRepurchase', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $policy->getStart()->getTimeZone());

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $policy->expire(new \DateTime('2017-01-01'));

        $repurchase = static::$policyService->repurchase($policy);
        $this->assertNotEquals($policy->getId(), $repurchase->getId());
        $this->assertEquals($policy->getImei(), $repurchase->getImei());
        $this->assertNull($repurchase->getStatus());

        $repurchase2 = static::$policyService->repurchase($policy);
        $this->assertEquals($repurchase->getId(), $repurchase2->getId());
    }

    public function testCreatePendingRenewalPolicies()
    {
        $policies = static::$policyService->createPendingRenewalPolicies(
            'TEST',
            false,
            new \DateTime('2016-12-15')
        );

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCreatePendingRenewalPolicies', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $policies = static::$policyService->createPendingRenewalPolicies(
            'TEST',
            false,
            new \DateTime('2016-12-01')
        );
        $this->assertEquals(0, count($policies));

        $policies = static::$policyService->createPendingRenewalPolicies(
            'TEST',
            false,
            new \DateTime('2016-12-15')
        );
        $this->assertEquals(1, count($policies));
    }

    public function testActivateRenewalPolicies()
    {
        $policies = static::$policyService->activateRenewalPolicies(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testActivateRenewalPolicies', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2016-12-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        static::$policyService->renew($policy, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicy->getStatus());

        $policies = static::$policyService->activateRenewalPolicies(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );
        $this->assertGreaterThan(0, count($policies));
    }

    public function testActivateRenewalCopyBacs()
    {
        $policies = static::$policyService->activateRenewalPolicies(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testActivateRenewalCopyBacs', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_SUCCESS);
        $oneMonthAgo = \DateTime::createFromFormat('U', time());
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P1M'));
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P5D'));

        $payment = static::addBacsPayPayment($policy, $oneMonthAgo, true);
        $payment->setStatus(BacsPayment::STATUS_SUCCESS);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2016-12-15')
        );
        $policy->expire(new \DateTime(date('Y-m-d h:i:s')));
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        static::$policyService->renew($policy, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicy->getStatus());

        $policies = static::$policyService->activateRenewalPolicies(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );
        $this->assertEquals($policy->getBacsBankAccount(), $renewalPolicy->getBacsBankAccount());
    }

    public function testFullyExpireExpiredClaimablePolicies()
    {
        $policies = static::$policyService->fullyExpireExpiredClaimablePolicies(
            'TEST',
            false,
            new \DateTime('2017-02-02')
        );

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testFullyExpireExpiredClaimablePolicies', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());
        static::$dm->flush();

        $policies = static::$policyService->expireEndingPolicies(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );
        $this->assertGreaterThan(0, count($policies));

        $policies = static::$policyService->fullyExpireExpiredClaimablePolicies(
            'TEST',
            false,
            new \DateTime('2017-02-02')
        );
        $this->assertGreaterThan(0, count($policies));

        $policies = static::$policyService->fullyExpireExpiredClaimablePolicies(
            'TEST',
            false,
            new \DateTime('2017-02-02')
        );
        $this->assertEquals(0, count($policies));
    }

    public function testSetUnpaidForCancelledMandate()
    {
        $policies = static::$policyService->setUnpaidForCancelledMandate(
            'TEST',
            false,
            new \DateTime('2017-02-02')
        );

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSetUnpaidForCancelledMandate', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        static::setBacsPaymentMethodForPolicy($policy);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());
        $policy->getPolicyOrUserBacsBankAccount()->setMandateStatus(BankAccount::MANDATE_CANCELLED);
        static::$dm->flush();

        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2016-01-02'), true, false, true));
        $policies = static::$policyService->setUnpaidForCancelledMandate(
            'TEST',
            false,
            new \DateTime('2016-01-02')
        );
        $this->assertEquals(0, count($policies));

        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2017-01-02'), true, false, true));
        $policies = static::$policyService->setUnpaidForCancelledMandate(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );
        $this->assertGreaterThan(0, count($policies));
        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);
        $this->assertEquals(Policy::STATUS_UNPAID, $updatedPolicy->getStatus());
    }

    public function testFullyExpireExpiredClaimablePoliciesWithClaim()
    {
        $policies = static::$policyService->fullyExpireExpiredClaimablePolicies(
            'TEST',
            false,
            new \DateTime('2017-02-02')
        );

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testFullyExpireExpiredClaimablePoliciesWithClaim', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $policy->addClaim($claim);
        static::$dm->flush();

        $policies = static::$policyService->expireEndingPolicies(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );
        $this->assertGreaterThan(0, count($policies));

        // 1st run to set to wait-claim and should always have policy
        $policies = static::$policyService->fullyExpireExpiredClaimablePolicies(
            'TEST',
            false,
            new \DateTime('2017-02-02')
        );
        //print_r($policies);
        $this->assertGreaterThan(0, count($policies));

        // 2nd run (and later) should still return policies as no flag set
        $policies = static::$policyService->fullyExpireExpiredClaimablePolicies(
            'TEST',
            false,
            new \DateTime('2017-02-02')
        );
        //print_r($policies);
        $this->assertGreaterThan(0, count($policies));
    }

    public function testFullyExpireExpiredClaimablePoliciesWithClaimAndIgnoreFlag()
    {
        $policies = static::$policyService->fullyExpireExpiredClaimablePolicies(
            'TEST',
            false,
            new \DateTime('2017-02-02')
        );
        $init = count(static::$policyService->fullyExpireExpiredClaimablePolicies(
            'TEST',
            false,
            new \DateTime('2017-02-02')
        ));


        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testFullyExpireExpiredClaimablePoliciesWithClaimAndIgnoreFlag', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setIgnoreWarningFlags(Claim::WARNING_FLAG_IGNORE_POLICY_EXPIRE_CLAIM_WAIT);
        $policy->addClaim($claim);
        static::$dm->flush();

        $policies = static::$policyService->expireEndingPolicies(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );
        $this->assertGreaterThan(0, count($policies));

        // 1st run to set to wait-claim and should always have policy
        $policies = static::$policyService->fullyExpireExpiredClaimablePolicies(
            'TEST',
            false,
            new \DateTime('2017-02-02')
        );
        $this->assertGreaterThan($init, count($policies));

        // 2nd run (and later) should return 0 policies
        $policies = static::$policyService->fullyExpireExpiredClaimablePolicies(
            'TEST',
            false,
            new \DateTime('2017-02-02')
        );
        $this->assertEquals($init, count($policies));

        // 9am should always return policies
        $policies = static::$policyService->fullyExpireExpiredClaimablePolicies(
            'TEST',
            false,
            new \DateTime('2017-02-02 09:05')
        );
        $this->assertGreaterThan($init, count($policies));
    }

    public function testUnRenewPolicies()
    {
        $policies = static::$policyService->unrenewPolicies(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testUnRenewPolicies', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2016-12-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        static::$policyService->declineRenew($policy, null, new \DateTime('2016-12-17'));
        $this->assertEquals(Policy::STATUS_DECLINED_RENEWAL, $renewalPolicy->getStatus());

        $policies = static::$policyService->unrenewPolicies(
            'TEST',
            false,
            new \DateTime('2016-12-31')
        );
        $this->assertEquals(0, count($policies));

        $policies = static::$policyService->unrenewPolicies(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );
        $this->assertEquals(1, count($policies));
    }

    public function testRenewPolicies()
    {
        $policies = static::$policyService->renewPolicies(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRenewPolicies', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2016-12-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        $policies = static::$policyService->renewPolicies(
            'TEST',
            false,
            new \DateTime('2016-12-31')
        );
        $this->assertEquals(0, count($policies));

        $policies = static::$policyService->renewPolicies(
            'TEST',
            false,
            new \DateTime('2017-01-02')
        );
        $this->assertGreaterThan(0, count($policies));
        $this->assertEquals($policy->getId(), array_keys($policies)[0]);
    }

    public function testPolicyRenewCashback()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewCashbackA', $this, true),
            static::generateEmail('testPolicyRenewCashbackB', $this, true)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        $cashback = $this->getCashback($policyA);
        $cashback->setAccountName('a');
        $exceptionThrown = false;
        try {
            static::$policyService->renew($policyA, 12, $cashback, false, new \DateTime('2016-12-30'));
        } catch (ValidationException $e) {
            $exceptionThrown = true;
        }
        $this->assertTrue($exceptionThrown);

        $cashback = $this->getCashback($policyA);
        $cashback->setSortCode('1');
        $exceptionThrown = false;
        try {
            static::$policyService->renew($policyA, 12, $cashback, false, new \DateTime('2016-12-30'));
        } catch (ValidationException $e) {
            $exceptionThrown = true;
        }
        $this->assertTrue($exceptionThrown);

        $cashback = $this->getCashback($policyA);
        $cashback->setAccountNumber('1');
        $exceptionThrown = false;
        try {
            static::$policyService->renew($policyA, 12, $cashback, false, new \DateTime('2016-12-30'));
        } catch (ValidationException $e) {
            $exceptionThrown = true;
        }
        $this->assertTrue($exceptionThrown);

        $cashback = $this->getCashback($policyA);
        static::$policyService->renew($policyA, 12, $cashback, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertNotNull($policyA->getCashback());
        $this->assertEquals(Cashback::STATUS_PENDING_CLAIMABLE, $policyA->getCashback()->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());
        $this->assertEquals(Cashback::STATUS_PENDING_CLAIMABLE, $policyA->getCashback()->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(
            $renewalPolicyA->getPremium()->getMonthlyPremiumPrice(),
            $renewalPolicyA->getNextScheduledPayment()->getAmount()
        );

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
        $this->assertEquals(Cashback::STATUS_PENDING_PAYMENT, $policyA->getCashback()->getStatus());
    }

    public function testPolicyCashbackThenRenew()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCashbackThenRenewA', $this, true),
            static::generateEmail('testPolicyCashbackThenRenewB', $this, true)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        $cashback = $this->getCashback($policyA);
        static::$policyService->cashback($policyA, $cashback);

        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());

        $this->assertEquals($cashback->getAccountNumber(), $policyA->getCashback()->getAccountNumber());
        $this->assertEquals(Cashback::STATUS_PENDING_CLAIMABLE, $policyA->getCashback()->getStatus());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        $this->assertNull($policyA->getCashback());
        $this->assertNotEquals(
            $renewalPolicyA->getPremium()->getMonthlyPremiumPrice(),
            $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice()
        );
        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice(),
            $renewalPolicyA->getNextScheduledPayment()->getAmount()
        );
    }

    public function testPolicyCashbackThenRenewWithCashback()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCashbackThenRenewWithCashbackA', $this, true),
            static::generateEmail('testPolicyCashbackThenRenewWithCashbackB', $this, true)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        $cashback = $this->getCashback($policyA);
        static::$policyService->cashback($policyA, $cashback);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedPolicyA = $policyRepo->find($policyA->getId());

        $this->assertEquals($cashback->getAccountNumber(), $updatedPolicyA->getCashback()->getAccountNumber());

        $cashback = $this->getCashback($policyA);
        $cashback->setAccountNumber('87654321');
        static::$policyService->renew($updatedPolicyA, 12, $cashback, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        $this->assertEquals($cashback->getAccountNumber(), $updatedPolicyA->getCashback()->getAccountNumber());
        $this->assertEquals(Cashback::STATUS_PENDING_CLAIMABLE, $updatedPolicyA->getCashback()->getStatus());
        $this->assertEquals(
            $renewalPolicyA->getPremium()->getMonthlyPremiumPrice(),
            $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice()
        );
        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice(),
            $renewalPolicyA->getNextScheduledPayment()->getAmount()
        );
    }

    public function testPolicyCashbackThenRenewWithDiscount()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCashbackThenRenewWithDiscountA', $this, true),
            static::generateEmail('testPolicyCashbackThenRenewWithDiscountB', $this, true)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        $cashback = $this->getCashback($policyA);
        static::$policyService->cashback($policyA, $cashback);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedPolicyA = $policyRepo->find($policyA->getId());

        $this->assertEquals($cashback->getAccountNumber(), $updatedPolicyA->getCashback()->getAccountNumber());

        static::$policyService->renew($updatedPolicyA, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        $this->assertNull($updatedPolicyA->getCashback());
        $this->assertNotEquals(
            $renewalPolicyA->getPremium()->getMonthlyPremiumPrice(),
            $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice()
        );
        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice(),
            $renewalPolicyA->getNextScheduledPayment()->getAmount()
        );
    }

    public function testPolicyCashbackUnrenew()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCashbackUnrenewA', $this, true),
            static::generateEmail('testPolicyCashbackUnrenewB', $this, true)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        $cashback = $this->getCashback($policyA);
        static::$policyService->cashback($policyA, $cashback);

        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());

        $this->assertEquals($cashback->getAccountNumber(), $policyA->getCashback()->getAccountNumber());
        $this->assertEquals(Cashback::STATUS_PENDING_CLAIMABLE, $policyA->getCashback()->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->unrenew($renewalPolicyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_UNRENEWED, $renewalPolicyA->getStatus());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
        $this->assertEquals(Cashback::STATUS_PENDING_PAYMENT, $policyA->getCashback()->getStatus());
    }

    public function testCancelPolicyUnpaidUnder15()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCancelPolicyUnpaidUnder15', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        $policy->setStatus(PhonePolicy::STATUS_UNPAID);
        static::$dm->flush();

        // Simulate the correct time for the log history
        $dm = clone self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $dm->createQueryBuilder(LogEntry::class)
        ->findAndUpdate()
        ->field('objectId')->equals($policy->getId())
        ->field('data.status')->equals(Policy::STATUS_UNPAID)
        ->field('loggedAt')->set(new \DateTime('2016-01-01'))
        ->getQuery()
        ->execute();
        $dm->flush();
        $dm->close();
        //static::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');

        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());
        $exception = false;
        try {
            static::$policyService->cancel($updatedPolicy, Policy::CANCELLED_UNPAID, true, new \DateTime('2016-01-14'));
        } catch (\Exception $e) {
            $exception = true;
            $this->assertTrue(mb_stripos($e->getMessage(), 'less than 15 days in unpaid state') > 0);
        }
        $this->assertTrue($exception);
    }

    public function testCancelPolicyUnpaidAfter15()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCancelPolicyUnpaidAfter15', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        $policy->setStatus(PhonePolicy::STATUS_UNPAID);
        static::$dm->flush();

        // Simulate the correct time for the log history
        $dm = clone self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $dm->createQueryBuilder(LogEntry::class)
        ->findAndUpdate()
        ->field('objectId')->equals($policy->getId())
        ->field('data.status')->equals(Policy::STATUS_UNPAID)
        ->field('loggedAt')->set(new \DateTime('2016-01-01'))
        ->getQuery()
        ->execute();
        $dm->flush();
        $dm->close();
        //static::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');

        $this->assertEquals(Policy::STATUS_UNPAID, $policy->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());
        static::$policyService->cancel($updatedPolicy, Policy::CANCELLED_UNPAID, true, new \DateTime('2016-01-16'));
    }

    public function testPolicyUnrenew()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyUnrenewA', $this, true),
            static::generateEmail('testPolicyUnrenewB', $this, true)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->declineRenew($policyA, null, new \DateTime('2016-12-31'));

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->unrenew($renewalPolicyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_UNRENEWED, $renewalPolicyA->getStatus());
        $this->assertEquals(Cashback::STATUS_MISSING, $policyA->getCashback()->getStatus());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
    }

    public function testPolicyPurchaseAgain()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyPurchaseAgainA', $this, true),
            static::generateEmail('testPolicyPurchaseAgainB', $this, true)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->declineRenew($policyA, null, new \DateTime('2016-12-31'));

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->unrenew($renewalPolicyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_UNRENEWED, $renewalPolicyA->getStatus());
        $this->assertEquals(Cashback::STATUS_MISSING, $policyA->getCashback()->getStatus());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        $newPolicyA = new SalvaPhonePolicy();
        $newPolicyA->setImei($policyA->getImei());
        $newPolicyA->setPhone($policyA->getPhone(), new \DateTime('2017-01-01'));
        $newPolicyA->init($policyA->getUser(), self::getLatestPolicyTerms(static::$dm));
        $newPolicyA->setPremiumInstallments(12);
        self::addPayment(
            $newPolicyA,
            $newPolicyA->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2017-01-01')
        );
        static::$dm->persist($newPolicyA);

        $newPolicyA->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($newPolicyA, new \DateTime('2017-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
    }

    public function testPolicyRenewStartDate()
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/London'));
        $weekAhead = $now->add(new \DateInterval('P10D'));
        $startDate = new \DateTime('-1 year', new \DateTimeZone('Europe/London'));
        $startDate = $startDate->add(new \DateInterval('P10D'));

        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewStartDateA', $this, true),
            static::generateEmail('testPolicyRenewStartDateB', $this, true),
            true,
            $startDate,
            $startDate
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        $renewedPolicyA = static::$policyService->renew($policyA, 12, null, false);
        $this->assertEquals($this->startOfDay($weekAhead)->add(new \DateInterval("PT4H")), $renewedPolicyA->getStart());
        $this->assertEquals(0, $renewedPolicyA->getStart()->format('H'));
        $this->assertEquals(new \DateTimeZone('Europe/London'), $renewedPolicyA->getStart()->getTimezone());
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewedPolicyA ->getStatus());
    }

    public function testPolicyRenewalConnections()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsA', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-15')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2017-01-10'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-15'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        $this->assertNotNull($renewalPolicyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyB->getId(),
            $renewalPolicyA->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-16'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(10, $renewalPolicyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsMultiYears()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsMultiYearsA', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsMultiYearsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-06-15')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2017-06-10'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-06-15'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        $this->assertNotNull($renewalPolicyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyB->getId(),
            $renewalPolicyA->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-06-16'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(10, $renewalPolicyB->getPotValue());

        $renewalPolicyAY2 = static::$policyService->createPendingRenewal(
            $renewalPolicyA,
            new \DateTime('2017-12-30')
        );
        static::$policyService->renew($renewalPolicyA, 12, null, false, new \DateTime('2017-12-31'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyAY2->getStatus());

        static::$policyService->expire($renewalPolicyA, new \DateTime('2018-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $renewalPolicyA->getStatus());

        static::$policyService->activate($renewalPolicyAY2, new \DateTime('2018-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyAY2->getStatus());

        $this->assertEquals(10, $renewalPolicyAY2->getPotValue());

        $renewalPolicyBY2 = static::$policyService->createPendingRenewal(
            $renewalPolicyB,
            new \DateTime('2018-06-10')
        );
        static::$policyService->renew($renewalPolicyB, 12, null, false, new \DateTime('2018-06-14'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyBY2->getStatus());

        static::$policyService->expire($renewalPolicyB, new \DateTime('2018-06-15'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $renewalPolicyB->getStatus());

        static::$policyService->activate($renewalPolicyBY2, new \DateTime('2018-06-16'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyBY2->getStatus());

        $this->assertEquals(10, $renewalPolicyBY2->getPotValue());
    }

    public function testPolicyRenewalConnectionsSingleReconnect()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsNoReconnectA', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsNoReconnectB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-15')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2017-01-10'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyB->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-01-15'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-16'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(0, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsSingleReconnectReverseUnder15()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverseA', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverseB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-05')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(0, $renewalPolicyA->getPotValue());

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-01-05'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) == 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-06'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(0, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsSingleReconnectReverseOver15()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverseOver15A', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverseOver15B', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-18')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(0, $renewalPolicyA->getPotValue());

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-01-18'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) == 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-19'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(0, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsUnder60()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsUnder60A', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsUnder60B', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-02-28')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-02-28'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-02-28'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-02-29'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(10, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(10, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsSingleReconnectUnder60()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectUnder60A', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectUnder60B', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-02-28')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-02-27'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyB->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-02-28'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-02-29'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(0, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsSingleReconnectReverseUnder60()
    {
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverseUnder60A', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverseUnder60B', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-02-28')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(0, $renewalPolicyA->getPotValue());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-02-27'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyB->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
        }
        $this->assertFalse($foundRenewalConnection);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());
        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());

        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) == 0);

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-02-28'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) == 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-02-29'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(0, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        // 12-2=10 months connected ; £10 * 10/12 = 8.33
        $this->assertEquals(8.33, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnections5Months()
    {
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnections5MonthsA', $this, true),
            static::generateEmail('testPolicyRenewalConnections5MonthsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-06-01')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-05-28'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-06-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-06-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(10, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(10, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsSingleReconnect5Months()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnect5MonthsA', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnect5MonthsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-06-01')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-05-27'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyB->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-06-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-06-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(0, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsSingleReconnectReverse5Months()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverse5MonthsA', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverse5MonthsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-06-01')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(0, $renewalPolicyA->getPotValue());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-05-27'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyB->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
        }
        $this->assertFalse($foundRenewalConnection);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());
        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());

        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) == 0);

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-06-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) == 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-06-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(0, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        // 12-5=7 months connected ; £10 * 7/12 = 5.83
        $this->assertEquals(5.83, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnections7Months()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnections7MonthsA', $this, true),
            static::generateEmail('testPolicyRenewalConnections7MonthsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-08-01')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-07-28'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-08-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-08-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(10, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(10, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsSingleReconnect7Months()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnect7MonthsA', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnect7MonthsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-08-01')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-07-27'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyB->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-08-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-08-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        // 7 months connected ; £10 * 7/12 = 5.83
        $this->assertEquals(5.83, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsSingleReconnectReverse7Months()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverse7MonthsA', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverse7MonthsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-08-01')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(0, $renewalPolicyA->getPotValue());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-07-27'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyB->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
        }
        $this->assertFalse($foundRenewalConnection);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());
        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());

        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) == 0);

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-08-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) == 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-08-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(0, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        // 12-7=5 months connected ; £0
        $this->assertEquals(0, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnections11Months()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnections11MonthsA', $this, true),
            static::generateEmail('testPolicyRenewalConnections11MonthsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-12-01')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-11-28'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-12-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-12-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(10, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(10, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsSingleReconnect11Months()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnect11MonthsA', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnect11MonthsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-12-01')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertEquals(
            $renewalPolicyA->getId(),
            $policyB->getConnections()[0]->getLinkedPolicyRenewal()->getId()
        );
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-11-27'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyB->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-12-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) > 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-12-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        // 11 months connected ; £10 * 11/12 = 9.17
        $this->assertEquals(9.17, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());
    }

    public function testPolicyRenewalConnectionsSingleReconnectReverse11Months()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverse11MonthsA', $this, true),
            static::generateEmail('testPolicyRenewalConnectionsSingleReconnectReverse11MonthsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-12-01')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(0, $renewalPolicyA->getPotValue());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-11-27'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        $foundRenewalConnection = false;
        foreach ($renewalPolicyB->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
        }
        $this->assertFalse($foundRenewalConnection);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());
        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());

        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) == 0);

        $updatedPolicyB = $policyRepo->find($policyB->getId());
        static::$policyService->expire($policyB, new \DateTime('2017-12-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyB->getStatus());

        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertNull($policyA->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyB->getAcceptedConnectionsRenewal()) == 0);

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-12-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(0, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        // 12-11=1 months connected ; £0
        $this->assertEquals(0, $policyB->getPotValue());
    }

    public function testPolicyRenewalSelfClaim()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalSelfClaimA', $this, true),
            static::generateEmail('testPolicyRenewalSelfClaimB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-01')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        $claimA = new Claim();
        $claimA->setLossDate(new \DateTime('2016-12-31'));
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $claimA->setType(Claim::TYPE_LOSS);
        $policyA->addClaim($claimA);

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());
        $this->assertEquals(10, $renewalPolicyB->getPotValue());

        $this->assertEquals(0, $policyA->getPotValue());
        $this->assertEquals(0, $policyB->getPotValue());
    }

    public function testPolicyRenewalNetworkClaim50()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalNetworkClaim40A', $this, true),
            static::generateEmail('testPolicyRenewalNetworkClaim40B', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-01'),
            4
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(50, $policyA->getPotValue());

        $claimB = new Claim();
        $claimB->setStatus(Claim::STATUS_SETTLED);
        $claimB->setType(Claim::TYPE_LOSS);
        $claimB->setLossDate(new \DateTime('2016-12-30'));
        $policyB->addClaim($claimB);

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(50, $renewalPolicyA->getPotValue());
        $this->assertEquals(10, $renewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(0, $policyB->getPotValue());
    }

    public function testPolicyRenewalNetworkClaim50WithDiscount()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalNetworkClaim40WithDiscountA', $this, true),
            static::generateEmail('testPolicyRenewalNetworkClaim40WithDiscountB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-01'),
            4
        );
        self::setPaymentMethodForPolicy($policyA);
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(50, $policyA->getPotValue());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(50, $renewalPolicyA->getPotValue());
        $this->assertEquals(10, $renewalPolicyB->getPotValue());

        $this->assertEquals(50, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $claimB = new Claim();
        $claimB->setStatus(Claim::STATUS_SETTLED);
        $claimB->setType(Claim::TYPE_LOSS);
        $claimB->setLossDate(new \DateTime('2016-12-30'));
        $claimB->setProcessed(true);
        $policyB->addClaim($claimB);

        $paymentA = new JudoPayment();
        $paymentA->setDate(new \DateTime('2017-01-01'));
        $paymentA->setAmount($renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $paymentA->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $scheduledPaymentA = $renewalPolicyA->getNextScheduledPayment();
        $scheduledPaymentA->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentA->setPayment($paymentA);
        $paymentA->setResult(JudoPayment::RESULT_SUCCESS);
        $policyA->getNextPolicy()->addPayment($paymentA);

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        $paymentB = new JudoPayment();
        $paymentB->setDate(new \DateTime('2017-01-01'));
        $paymentB->setAmount($renewalPolicyB->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $paymentB->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $scheduledPaymentB = $renewalPolicyB->getNextScheduledPayment();
        $scheduledPaymentB->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentB->setPayment($paymentB);
        $paymentB->setResult(JudoPayment::RESULT_SUCCESS);
        $policyB->getNextPolicy()->addPayment($paymentB);
        static::$dm->flush();

        static::$policyService->fullyExpire($policyB, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyB->getStatus());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(0, $policyB->getPotValue());

        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice() - $paymentA->getAmount(),
            $renewalPolicyA->getOutstandingPremium()
        );

        // 12 original scheduled (1 paid, 11 cancelled)
        // 11 new scheduled (monthly) as 1 already paid
        // 1 adjustment to rebate
        $this->assertEquals(24, count($renewalPolicyA->getScheduledPayments()));
        // 10/12 = 0.83
        // first payment should be (annual policy - 50) / 12
        $this->assertEquals(
            $this->toTopTwoDp(($policyA->getNextPolicy()->getPremium()->getYearlyPremiumPrice() - 50) / 12),
            $paymentA->getAmount()
        );
        // 2nd payment should be (50-10)/12=3.33
        $this->assertEquals(
            3.33,
            $policyA->getNextPolicy()->getNextScheduledPayment()->getAmount()
        );
    }

    public function testPolicyRenewalNetworkClaim10WithDiscount()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalNetworkClaim10WithDiscountA', $this, true),
            static::generateEmail('testPolicyRenewalNetworkClaim10WithDiscountB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-01')
        );
        self::setPaymentMethodForPolicy($policyA);
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(10, $policyA->getPotValue());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());
        $this->assertEquals(10, $renewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $claimB = new Claim();
        $claimB->setStatus(Claim::STATUS_SETTLED);
        $claimB->setType(Claim::TYPE_LOSS);
        $claimB->setLossDate(new \DateTime('2016-12-30'));
        $claimB->setProcessed(true);
        $policyB->addClaim($claimB);

        $paymentA = new JudoPayment();
        $paymentA->setDate(new \DateTime('2017-01-01'));
        $paymentA->setAmount($renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $paymentA->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $scheduledPaymentA = $renewalPolicyA->getNextScheduledPayment();
        $scheduledPaymentA->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentA->setPayment($paymentA);
        $paymentA->setResult(JudoPayment::RESULT_SUCCESS);
        $policyA->getNextPolicy()->addPayment($paymentA);

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        $paymentB = new JudoPayment();
        $paymentB->setDate(new \DateTime('2017-01-01'));
        $paymentB->setAmount($renewalPolicyB->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $paymentB->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $scheduledPaymentB = $renewalPolicyB->getNextScheduledPayment();
        $scheduledPaymentB->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentB->setPayment($paymentB);
        $paymentB->setResult(JudoPayment::RESULT_SUCCESS);
        $policyB->getNextPolicy()->addPayment($paymentB);
        static::$dm->flush();

        static::$policyService->fullyExpire($policyB, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyB->getStatus());

        $this->assertEquals(0, $policyA->getPotValue());
        $this->assertEquals(0, $policyB->getPotValue());

        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice() - $paymentA->getAmount(),
            $renewalPolicyA->getOutstandingPremium()
        );

        // 12 original scheduled (1 paid, 11 cancelled)
        // 11 new scheduled (monthly) as 1 already paid
        // 1 adjustment to rebate
        $this->assertEquals(24, count($renewalPolicyA->getScheduledPayments()));
        $this->assertEquals(
            $policyA->getNextPolicy()->getPremium()->getAdjustedStandardMonthlyPremiumPrice(),
            $paymentA->getAmount() + $policyA->getNextPolicy()->getNextScheduledPayment()->getAmount()
        );
    }

    public function testPolicyRenewalNetworkClaim10WithDiscountBacs()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalNetworkClaim10WithDiscountBacsA', $this, true),
            static::generateEmail('testPolicyRenewalNetworkClaim10WithDiscountBacsB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-01')
        );
        self::setBacsPaymentMethodForPolicy($policyA);
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(10, $policyA->getPotValue());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());
        $this->assertEquals(10, $renewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $claimB = new Claim();
        $claimB->setStatus(Claim::STATUS_SETTLED);
        $claimB->setType(Claim::TYPE_LOSS);
        $claimB->setLossDate(new \DateTime('2016-12-30'));
        $claimB->setProcessed(true);
        $policyB->addClaim($claimB);

        $paymentA = new JudoPayment();
        $paymentA->setDate(new \DateTime('2017-01-01'));
        $paymentA->setAmount($renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $paymentA->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $scheduledPaymentA = $renewalPolicyA->getNextScheduledPayment();
        $scheduledPaymentA->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentA->setPayment($paymentA);
        $paymentA->setResult(JudoPayment::RESULT_SUCCESS);
        $policyA->getNextPolicy()->addPayment($paymentA);

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        $paymentB = new JudoPayment();
        $paymentB->setDate(new \DateTime('2017-01-01'));
        $paymentB->setAmount($renewalPolicyB->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $paymentB->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $scheduledPaymentB = $renewalPolicyB->getNextScheduledPayment();
        $scheduledPaymentB->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentB->setPayment($paymentB);
        $paymentB->setResult(JudoPayment::RESULT_SUCCESS);
        $policyB->getNextPolicy()->addPayment($paymentB);
        static::$dm->flush();

        static::$policyService->fullyExpire($policyB, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyB->getStatus());

        $this->assertEquals(0, $policyA->getPotValue());
        $this->assertEquals(0, $policyB->getPotValue());

        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice() - $paymentA->getAmount(),
            $renewalPolicyA->getOutstandingPremium()
        );

        // 12 original scheduled (1 paid, 11 cancelled)
        // 11 new scheduled (monthly) as 1 already paid
        // 0 adjustments to rebate
        $this->assertEquals(23, count($renewalPolicyA->getScheduledPayments()));
        $this->assertEquals(
            $policyA->getNextPolicy()->getPremium()->getAdjustedStandardMonthlyPremiumPrice(),
            $policyA->getNextPolicy()->getNextScheduledPayment()->getAmount()
        );
    }

    public function testPolicyRenewalNetworkClaimOpenWithDiscount()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalNetworkClaimOpenWithDiscount-A', $this, true),
            static::generateEmail('testPolicyRenewalNetworkClaimOpenWithDiscount-B', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-01')
        );

        $claimB = new Claim();
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $claimB->setType(Claim::TYPE_LOSS);
        $claimB->setLossDate(new \DateTime('2016-12-30'));
        $claimB->setProcessed(true);
        $policyB->addClaim($claimB);

        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(10, $policyA->getPotValue());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());
        $this->assertEquals(10, $renewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $paymentA = new JudoPayment();
        $paymentA->setDate(new \DateTime('2017-01-01'));
        $paymentA->setAmount($renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $paymentA->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $scheduledPaymentA = $renewalPolicyA->getNextScheduledPayment();
        $scheduledPaymentA->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentA->setPayment($paymentA);
        $paymentA->setResult(JudoPayment::RESULT_SUCCESS);
        $policyA->getNextPolicy()->addPayment($paymentA);

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED_WAIT_CLAIM, $policyA->getStatus());

        $paymentB = new JudoPayment();
        $paymentB->setDate(new \DateTime('2017-01-01'));
        $paymentB->setAmount($renewalPolicyB->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $paymentB->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $scheduledPaymentB = $renewalPolicyB->getNextScheduledPayment();
        $scheduledPaymentB->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentB->setPayment($paymentB);
        $paymentB->setResult(JudoPayment::RESULT_SUCCESS);
        $policyB->getNextPolicy()->addPayment($paymentB);
        static::$dm->flush();

        static::$policyService->fullyExpire($policyB, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED_WAIT_CLAIM, $policyB->getStatus());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $this->assertFalse($policyA->getNextPolicy()->getPremium()->hasAnnualDiscount());
        $this->assertFalse($policyB->getNextPolicy()->getPremium()->hasAnnualDiscount());

        // 12 original scheduled (1 paid, 11 cancelled)
        $this->assertEquals(12, count($renewalPolicyA->getScheduledPayments()));
        $this->assertEquals(
            $policyA->getNextPolicy()->getPremium()->getMonthlyPremiumPrice(),
            $policyA->getNextPolicy()->getPremium()->getAdjustedStandardMonthlyPremiumPrice()
        );
        $this->assertEquals(
            $policyB->getNextPolicy()->getPremium()->getMonthlyPremiumPrice(),
            $policyB->getNextPolicy()->getPremium()->getAdjustedStandardMonthlyPremiumPrice()
        );
    }

    /**
    public function testPolicyRenewalPreNetworkClaim10()
    {
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalPreNetworkClaim10A', $this),
            static::generateEmail('testPolicyRenewalPreNetworkClaim10B', $this),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-01')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(10, $policyA->getPotValue());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-29'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $claimB = new Claim();
        $claimB->setStatus(Claim::STATUS_SETTLED);
        $claimB->setType(Claim::TYPE_LOSS);
        $claimB->setLossDate(new \DateTime('2016-12-30'));
        $policyB->addClaim($claimB);

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedPolicyA = $policyRepo->find($policyA->getId());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());
        $this->assertEquals(10, $renewalPolicyB->getPotValue());

        $this->assertEquals(0, $updatedPolicyA->getPotValue());
        $this->assertEquals(0, $policyB->getPotValue());

        $paymentA = new JudoPayment();
        $paymentA->setDate(new \DateTime('2017-01-01'));
        $paymentA->setAmount($renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $paymentA->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $scheduledPaymentA = $renewalPolicyA->getNextScheduledPayment();
        $scheduledPaymentA->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentA->setPayment($paymentA);
        $paymentA->setResult(JudoPayment::RESULT_SUCCESS);
        $policyA->getNextPolicy()->addPayment($paymentA);

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        $paymentB = new JudoPayment();
        $paymentB->setDate(new \DateTime('2017-01-01'));
        $paymentB->setAmount($renewalPolicyB->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $paymentB->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $scheduledPaymentB = $renewalPolicyB->getNextScheduledPayment();
        $scheduledPaymentB->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentB->setPayment($paymentB);
        $paymentB->setResult(JudoPayment::RESULT_SUCCESS);
        $policyB->getNextPolicy()->addPayment($paymentB);
        static::$dm->flush();

        static::$policyService->fullyExpire($policyB, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyB->getStatus());

        $this->assertEquals(0, $policyA->getPotValue());
        $this->assertEquals(0, $policyB->getPotValue());

        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice() - $paymentA->getAmount(),
            $renewalPolicyA->getOutstandingPremium()
        );

        // 12 original scheduled (1 paid, 11 cancelled)
        $this->assertEquals(12, count($renewalPolicyA->getScheduledPayments()));
        $this->assertEquals(
            $policyA->getNextPolicy()->getPremium()->getAdjustedStandardMonthlyPremiumPrice(),
            $paymentA->getAmount()
        );
    }
    */

    /**
     *
     */
    private function getCashback($policy)
    {
        $cashback = new Cashback();
        $cashback->setDate(\DateTime::createFromFormat('U', time()));
        $cashback->setStatus(Cashback::STATUS_PENDING_CLAIMABLE);
        $cashback->setAccountName('foo');
        $cashback->setSortCode('123456');
        $cashback->setAccountNumber('12345678');
        $cashback->setPolicy($policy);

        return $cashback;
    }

    public function testPolicyActive()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyActiveA', $this, true),
            static::generateEmail('testPolicyActiveB', $this, true),
            false
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        $this->assertEquals(
            new \DateTime('2017-01-31'),
            $renewalPolicyA->getPolicyExpirationDate(new \DateTime('2017-01-01'))
        );

        for ($i = 1; $i <= 11; $i++) {
            static::addPayment(
                $renewalPolicyA,
                $renewalPolicyA->getPremium()->getMonthlyPremiumPrice(),
                Salva::MONTHLY_TOTAL_COMMISSION
            );
        }
        static::addPayment(
            $renewalPolicyA,
            $renewalPolicyA->getPremium()->getMonthlyPremiumPrice(),
            Salva::FINAL_MONTHLY_TOTAL_COMMISSION
        );

        $premium = $renewalPolicyA->getPremium();
        $this->assertEquals($premium->getYearlyPremiumPrice(), $renewalPolicyA->getTotalPremiumPrice());
        $this->assertEquals($premium->getYearlyGwp(), $renewalPolicyA->getTotalGwp());
        $this->assertEquals($premium->getYearlyGwp(), $renewalPolicyA->getUsedGwp());
        $this->assertEquals($premium->getYearlyIpt(), $renewalPolicyA->getTotalIpt());
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $renewalPolicyA->getTotalBrokerFee());
        $this->assertEquals(0, $renewalPolicyA->getOutstandingPremium());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
    }

    public function testPolicyActiveWithConnections()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyActiveWithConnectionsA', $this, true),
            static::generateEmail('testPolicyActiveWithConnectionsB', $this, true)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());
        $this->assertNull($renewalPolicyB->getPendingCancellation());

        $this->assertNull($policyA->getCashback());
        $this->assertNull($policyB->getCashback());
        $this->assertNotEquals(
            $renewalPolicyA->getPremium()->getMonthlyPremiumPrice(),
            $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice()
        );
        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice(),
            $renewalPolicyA->getNextScheduledPayment()->getAmount()
        );

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $foundRenewalConnection = false;
        foreach ($updatedRenewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
        }
        $this->assertTrue($foundRenewalConnection);

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertEquals(10, $renewalPolicyA->getPotValue());
        $this->assertEquals(10, $renewalPolicyB->getPotValue());
        $this->assertEquals(
            new \DateTime('2017-01-31'),
            $renewalPolicyA->getPolicyExpirationDate(new \DateTime('2017-01-01'))
        );
        $this->assertEquals(
            new \DateTime('2017-01-31'),
            $renewalPolicyB->getPolicyExpirationDate(new \DateTime('2017-01-01'))
        );

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
    }

    public function testPolicyPaymentsRenewalWithConnections()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyPaymentsRenewalWithConnectionsA', $this, true),
            static::generateEmail('testPolicyPaymentsRenewalWithConnectionsB', $this, true)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());
        $this->assertNull($renewalPolicyB->getPendingCancellation());

        $this->assertNull($policyA->getCashback());
        $this->assertNull($policyB->getCashback());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        static::$policyService->fullyExpire($policyB, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyB->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertNotEquals(
            $renewalPolicyA->getPremium()->getMonthlyPremiumPrice(),
            $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice()
        );
        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice(),
            $renewalPolicyA->getNextScheduledPayment()->getAmount()
        );
        $this->assertEquals(
            10,
            $renewalPolicyA->getPremium()->getAnnualDiscount()
        );

        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice(),
            $renewalPolicyA->getOutstandingPremium()
        );

        for ($i = 1; $i <= 11; $i++) {
            $scheduledPayment = $renewalPolicyA->getNextScheduledPayment();
            $this->assertEquals(
                $scheduledPayment->getAmount(),
                $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice()
            );
            $payment = static::addPayment(
                $renewalPolicyA,
                $renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice(),
                Salva::MONTHLY_TOTAL_COMMISSION
            );
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SUCCESS);
            $scheduledPayment->setPayment($payment);
        }
        $scheduledPayment = $renewalPolicyA->getNextScheduledPayment();
        $this->assertEquals(
            $scheduledPayment->getAmount(),
            $renewalPolicyA->getPremium()->getAdjustedFinalMonthlyPremiumPrice()
        );
        $payment = static::addPayment(
            $renewalPolicyA,
            $renewalPolicyA->getPremium()->getAdjustedFinalMonthlyPremiumPrice(),
            Salva::FINAL_MONTHLY_TOTAL_COMMISSION
        );
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPayment->setPayment($payment);

        $premium = $renewalPolicyA->getPremium();
        $this->assertEquals($premium->getYearlyPremiumPrice(), $renewalPolicyA->getTotalPremiumPrice());
        $this->assertEquals($premium->getYearlyGwp(), $renewalPolicyA->getTotalGwp());
        $this->assertEquals($premium->getYearlyGwp(), $renewalPolicyA->getUsedGwp());
        $this->assertEquals($premium->getYearlyIpt(), $renewalPolicyA->getTotalIpt());
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $renewalPolicyA->getTotalBrokerFee());
        //\Doctrine\Common\Util\Debug::dump($renewalPolicyA->getPayments(), 3);
        $this->assertEquals(0, $renewalPolicyA->getOutstandingPremium());
    }

    public function testYearlyPolicyPaymentsRenewalWithConnections()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testYearlyPolicyPaymentsRenewalWithConnectionsA', $this, true),
            static::generateEmail('testYearlyPolicyPaymentsRenewalWithConnectionsB', $this, true)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->renew($policyA, 1, null, false, new \DateTime('2016-12-30'));
        static::$policyService->renew($policyB, 1, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());
        $this->assertNull($renewalPolicyB->getPendingCancellation());

        $this->assertNull($policyA->getCashback());
        $this->assertNull($policyB->getCashback());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        static::$policyService->fullyExpire($policyB, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyB->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertNotEquals(
            $renewalPolicyA->getPremium()->getYearlyPremiumPrice(),
            $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice()
        );
        $this->assertEquals(
            10,
            $renewalPolicyA->getPremium()->getAnnualDiscount()
        );

        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice(),
            $renewalPolicyA->getOutstandingPremium()
        );

        $scheduledPayment = $renewalPolicyA->getNextScheduledPayment();
        $this->assertEquals(
            $scheduledPayment->getAmount(),
            $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice()
        );
        $payment = static::addPayment(
            $renewalPolicyA,
            $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice(),
            Salva::YEARLY_TOTAL_COMMISSION
        );
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPayment->setPayment($payment);

        $premium = $renewalPolicyA->getPremium();
        $this->assertEquals($premium->getYearlyPremiumPrice(), $renewalPolicyA->getTotalPremiumPrice());
        $this->assertEquals($premium->getYearlyGwp(), $renewalPolicyA->getTotalGwp());
        $this->assertEquals($premium->getYearlyGwp(), $renewalPolicyA->getUsedGwp());
        $this->assertEquals($premium->getYearlyIpt(), $renewalPolicyA->getTotalIpt());
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $renewalPolicyA->getTotalBrokerFee());
        //\Doctrine\Common\Util\Debug::dump($renewalPolicyA->getPayments(), 3);
        $this->assertEquals(0, $renewalPolicyA->getOutstandingPremium());
    }

    public function testPotRewardWithClaim()
    {
        $userA = static::createUser(
            static::$userManager,
            static::generateEmail('testPotRewardWithClaimA', $this, true),
            'bar',
            static::$dm
        );
        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('testPotRewardWithClaimB', $this, true),
            'bar',
            static::$dm
        );
        $policyA = static::initPolicy(
            $userA,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        $policyB = static::initPolicy(
            $userB,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        self::setPaymentMethodForPolicy($policyA);
        $policyA->setStatus(PhonePolicy::STATUS_PENDING);
        $policyB->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policyA, new \DateTime('2016-01-01'), true);
        static::$policyService->create($policyB, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);

        $this->assertEquals(Policy::STATUS_ACTIVE, $policyA->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $policyB->getStatus());

        $renewalPolicyA = static::$policyService->createPendingRenewal(
            $policyA,
            new \DateTime('2016-12-15')
        );
        $renewalPolicyB = static::$policyService->createPendingRenewal(
            $policyB,
            new \DateTime('2016-12-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());
        $this->assertNull($renewalPolicyB->getPendingCancellation());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());
        $this->assertEquals(12, count($renewalPolicyA->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED)));
        $this->assertEquals(12, count($renewalPolicyB->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED)));

        $this->assertEquals(10, $renewalPolicyA->getPotValue());
        $this->assertEquals(10, $renewalPolicyB->getPotValue());
        $this->assertEquals(
            new \DateTime('2017-01-31'),
            $renewalPolicyA->getPolicyExpirationDate(new \DateTime('2017-01-01'))
        );
        $this->assertEquals(
            new \DateTime('2017-01-31'),
            $renewalPolicyB->getPolicyExpirationDate(new \DateTime('2017-01-01'))
        );

        $paymentA = new JudoPayment();
        $paymentA->setDate(new \DateTime('2017-01-01'));
        $paymentA->setAmount($renewalPolicyA->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
        $paymentA->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $scheduledPaymentA = $renewalPolicyA->getNextScheduledPayment();
        $scheduledPaymentA->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentA->setPayment($paymentA);
        $paymentA->setResult(JudoPayment::RESULT_SUCCESS);
        $policyA->getNextPolicy()->addPayment($paymentA);
        static::$dm->flush();
        $this->assertNotEquals(
            $policyA->getNextPolicy()->getPremium()->getMonthlyPremiumPrice(),
            $policyA->getNextPolicy()->getPremium()->getAdjustedStandardMonthlyPremiumPrice()
        );
        //\Doctrine\Common\Util\Debug::dump($policyA->getNextPolicy()->getPayments(), 3);
        //\Doctrine\Common\Util\Debug::dump($paymentA);

        $this->assertEquals(
            $this->toTwoDp(10/12) + $paymentA->getAmount(),
            $policyA->getNextPolicy()->getTotalSuccessfulPayments(new \DateTime('2017-01-02'), true)
        );
        $this->assertEquals(
            10 + $paymentA->getAmount(),
            $policyA->getNextPolicy()->getTotalSuccessfulPayments(new \DateTime('2017-01-02'))
        );

        $paymentB = new JudoPayment();
        $paymentB->setDate(new \DateTime('2017-01-01'));
        $paymentB->setAmount($renewalPolicyB->getPremium()->getMonthlyPremiumPrice());
        $paymentB->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $paymentB->setResult(JudoPayment::RESULT_SUCCESS);
        $scheduledPaymentB = $renewalPolicyB->getNextScheduledPayment();
        $scheduledPaymentB->setStatus(ScheduledPayment::STATUS_SUCCESS);
        $scheduledPaymentB->setPayment($paymentB);
        $policyB->getNextPolicy()->addPayment($paymentB);
        $this->assertNotEquals(
            $policyB->getNextPolicy()->getPremium()->getMonthlyPremiumPrice(),
            $policyB->getNextPolicy()->getPremium()->getAdjustedStandardMonthlyPremiumPrice()
        );

        $this->assertEquals(
            $this->toTwoDp(10/12) + $paymentB->getAmount(),
            $policyB->getNextPolicy()->getTotalSuccessfulPayments(new \DateTime('2017-01-02'), true)
        );
        $this->assertEquals(
            10 + $paymentB->getAmount(),
            $policyB->getNextPolicy()->getTotalSuccessfulPayments(new \DateTime('2017-01-02'))
        );

        $claimA = new Claim();
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $claimA->setType(Claim::TYPE_LOSS);
        $claimA->setProcessed(true);
        $policyA->addClaim($claimA);

        $this->assertNotNull($policyA->getNextPolicy());
        $this->assertNotNull($policyA->getNextPolicy()->getUser());
        static::$policyService->fullyExpire($policyA, new \DateTime('2017-02-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
        // use policyA->getNextPolicy to avoid having to flush/reload from db
        // Note that this behaviour is arguably a bug and may need to change. If policy had a judopay payment method
        // subtracting the £0.83 would not be necessary because it would create a scheduled payment to make the user
        // pay that amount.
        $due = $policyA->getNextPolicy()->getPremium()->getYearlyPremiumPrice() - $paymentA->getAmount() * 2 - 0.83;

        $this->assertEquals(
            $due,
            $policyA->getNextPolicy()->getOutstandingScheduledPaymentsAmount()
        );

        static::$policyService->fullyExpire($policyB, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyB->getStatus());
    }

    public function testUpdateCashback()
    {
        $userA = static::createUser(
            static::$userManager,
            static::generateEmail('testUpdateCashbackA', $this, true),
            'bar',
            static::$dm
        );
        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('testUpdateCashbackB', $this, true),
            'bar',
            static::$dm
        );
        $policyA = static::initPolicy(
            $userA,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        $policyB = static::initPolicy(
            $userB,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policyA->setStatus(PhonePolicy::STATUS_PENDING);
        $policyB->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policyA, new \DateTime('2016-01-01'), true);
        static::$policyService->create($policyB, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        list($connectionA, $connectionB) = $this->createLinkedConnections(
            $policyA,
            $policyB,
            10,
            10,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-01')
        );

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $policyA = $policyRepo->find($policyA->getId());
        $policyB = $policyRepo->find($policyB->getId());

        $this->assertEquals(Policy::STATUS_ACTIVE, $policyA->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $policyB->getStatus());

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());


        $renewalPolicyA = static::$policyService->createPendingRenewal(
            $policyA,
            new \DateTime('2016-12-15')
        );
        $renewalPolicyB = static::$policyService->createPendingRenewal(
            $policyB,
            new \DateTime('2016-12-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB->getStatus());

        $cashbackB = new Cashback();
        $cashbackB->setDate(\DateTime::createFromFormat('U', time()));
        $cashbackB->setStatus(Cashback::STATUS_PENDING_CLAIMABLE);
        $cashbackB->setAccountName('foo bar');
        $cashbackB->setSortCode('123456');
        $cashbackB->setAccountNumber('12345678');
        $cashbackB->setAmount(10);
        $cashbackB->setPolicy($policyB);
        static::$dm->persist($cashbackB);
        static::$dm->flush();

        foreach ([
            Cashback::STATUS_PAID,
            Cashback::STATUS_FAILED,
            Cashback::STATUS_MISSING,
            Cashback::STATUS_CLAIMED,
            Cashback::STATUS_PENDING_PAYMENT,
            Cashback::STATUS_PENDING_WAIT_CLAIM,
        ] as $status) {
            static::$policyService->updateCashback($cashbackB, $status);
            $this->assertEquals($status, $cashbackB->getStatus());
        }
    }

    public function testPolicyActiveWithConnectionsNoReconnect()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyActiveWithConnectionsNoReconnectA', $this, true),
            static::generateEmail('testPolicyActiveWithConnectionsNoReconnectB', $this, true)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $cashbackB = new Cashback();
        $cashbackB->setDate(\DateTime::createFromFormat('U', time()));
        $cashbackB->setStatus(Cashback::STATUS_PENDING_CLAIMABLE);
        $cashbackB->setAccountName('foo bar');
        $cashbackB->setSortCode('123456');
        $cashbackB->setAccountNumber('12345678');
        $cashbackB->setPolicy($policyB);
        static::$dm->persist($cashbackB);
        static::$dm->flush();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        static::$policyService->renew($policyB, 12, $cashbackB, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());
        $this->assertNull($renewalPolicyB->getPendingCancellation());
        $this->assertTrue($policyA->isRenewed());
        $this->assertTrue($policyB->isRenewed());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $foundRenewalConnection = false;
        foreach ($updatedRenewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-02'));
        $this->assertTrue($policyA->isRenewed());
        $this->assertTrue($policyB->isRenewed());
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

//        $this->assertEquals(0, $renewalPolicyA->getPotValue());
        $this->assertEquals(10, $renewalPolicyB->getPotValue());
        $this->assertEquals(
            new \DateTime('2017-01-31'),
            $renewalPolicyA->getPolicyExpirationDate(new \DateTime('2017-01-01'))
        );
        $this->assertEquals(
            new \DateTime('2017-01-31'),
            $renewalPolicyB->getPolicyExpirationDate(new \DateTime('2017-01-01'))
        );

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $this->assertTrue($policyA->isRenewed());
        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());
        $this->assertNull($policyA->getCashback());

        $this->assertTrue($policyB->isRenewed());
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());
        $this->assertGreaterThan(0, $policyB->getPotValue());
        $this->assertNotNull($policyB->getCashback());
        $this->assertEquals(10, $policyB->getCashback()->getAmount());
        $this->assertEquals(Cashback::STATUS_PENDING_CLAIMABLE, $policyB->getCashback()->getStatus());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
        $this->assertNull($policyA->getCashback());

        static::$policyService->fullyExpire($policyB, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyB->getStatus());
        $this->assertEquals(10, $policyB->getCashback()->getAmount());
        $this->assertEquals(Cashback::STATUS_PENDING_PAYMENT, $policyB->getCashback()->getStatus());

        $this->assertTrue($renewalPolicyA->getPremium()->hasAnnualDiscount());
        $this->assertEquals(10, $renewalPolicyA->getPremium()->getAnnualDiscount());

        $total = 0;
        $premium = $renewalPolicyA->getPremium();
        $foundStartDate = false;
        foreach ($renewalPolicyA->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED) as $scheduledPayment) {
            //print_r($scheduledPayment->getScheduled()->diff($renewalPolicyA->getStart()));
            if (abs($scheduledPayment->getScheduled()->diff($renewalPolicyA->getStart())->days) == 0) {
                $foundStartDate = true;
            }
            $this->assertTrue(
                $scheduledPayment->getAmount() == $premium->getAdjustedStandardMonthlyPremiumPrice() ||
                $scheduledPayment->getAmount() == $premium->getAdjustedFinalMonthlyPremiumPrice()
            );
            $total += $scheduledPayment->getAmount();
        }
        $this->assertEquals($total, $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice());
        $this->assertTrue($foundStartDate);
    }

    public function testNotReconnectedDoesNotAppear()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testNotReconnectedDoesNotAppearA', $this, true),
            static::generateEmail('testNotReconnectedDoesNotAppearB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-02-15')
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());
        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());

        $foundRenewalConnection = false;
        foreach ($updatedRenewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();
        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());

        static::$policyService->renew($policyB, 12, null, false, new \DateTime('2017-02-14'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertNull($renewalPolicyB->getPendingCancellation());

        $foundRenewalConnection = false;
        foreach ($updatedRenewalPolicyB->getRenewalConnections() as $connection) {
            $foundRenewalConnection = true;
        }
        $this->assertFalse($foundRenewalConnection);

        $this->assertEquals(Policy::STATUS_ACTIVE, $policyB->getStatus());
        static::$policyService->expire($policyB, new \DateTime('2017-02-15'));

        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-02-16'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());
        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertEquals(0, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());

        $this->assertEquals(10, $policyA->getPotValue());
        // 10 months connected
        $this->assertEquals(8.33, $policyB->getPotValue());
    }

    public function testSalvaRenewalCooloff()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSalvaRenewalCooloff', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );
        static::addJudoPayPayment(self::$judopay, $policy, new \DateTime('2016-01-01'));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            new \DateTime('2016-12-15')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        static::$policyService->renew($policy, 12, null, false, new \DateTime('2016-12-25'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicy->getStatus());
        $this->assertNull($renewalPolicy->getPendingCancellation());

        static::$policyService->cancel(
            $renewalPolicy,
            PhonePolicy::CANCELLED_COOLOFF,
            false,
            new \DateTime('2016-12-30')
        );
        $this->assertEquals(0, $renewalPolicy->getTotalPremiumPrice());
        $this->assertEquals(0, $renewalPolicy->getTotalGwp());
        $this->assertEquals(0, $renewalPolicy->getUsedGwp());
        $this->assertEquals(0, $renewalPolicy->getTotalIpt());
        $this->assertEquals(0, $renewalPolicy->getTotalBrokerFee());
    }

    public function testPolicyCancellationEmail()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCancellationEmailA', $this, true),
            static::generateEmail('testPolicyCancellationEmailB', $this, true)
        );
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();

        // Should be the cancellation email and notification about pot reduction
        $cancellationEmail = $policyA->getUser()->getEmail();
        $potReductionEmail = $policyB->getUser()->getEmail();

        $this->expectPotRewardEmail($mailer, 0, $potReductionEmail);
        $this->expectCancellationEmail($mailer, 1, $cancellationEmail);

        self::$policyService->setMailerMailer($mailer);

        self::$policyService->cancel($policyA, Policy::CANCELLED_USER_REQUESTED, false, new \DateTime('2016-10-01'));
    }

    public function testPolicyCancellationEmailUpgrade()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCancellationEmailUpgradeA', $this, true),
            static::generateEmail('testPolicyCancellationEmailUpgradeB', $this, true)
        );
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();

        // Should just be the cancellation email
        $cancellationEmail = $policyA->getUser()->getEmail();
        $this->expectCancellationEmail($mailer, 0, $cancellationEmail);
        self::$policyService->setMailerMailer($mailer);

        self::$policyService->cancel($policyA, Policy::CANCELLED_UPGRADE);
    }

    private function expectPotRewardEmail($mailer, $at, $email)
    {
        $mailer->expects($this->at($at))
            ->method('send')
            ->with($this->callback(
                function ($mail) use ($email) {
                    return in_array($email, array_keys($mail->getTo())) &&
                        mb_stripos($mail->getSubject(), 'your so-sure Reward Pot') !== false;
                }
            ));
    }

    private function expectCancellationEmail($mailer, $at, $email)
    {
        $mailer->expects($this->at($at))
            ->method('send')
            ->with($this->callback(
                function ($mail) use ($email) {
                    return in_array($email, array_keys($mail->getTo())) &&
                        mb_stripos($mail->getSubject(), 'is now cancelled') !== false;
                }
            ));
    }

    private function expectExpirationEmail($mailer, $at, $email)
    {
        $mailer->expects($this->at($at))
            ->method('send')
            ->with($this->callback(
                function ($mail) use ($email) {
                    return in_array($email, array_keys($mail->getTo())) &&
                        mb_stripos($mail->getSubject(), 'is now finished') !== false;
                }
            ));
    }

    public function testPolicyCancellationEmailNotRenewed()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCancellationEmailNotRenewedA', $this, true),
            static::generateEmail('testPolicyCancellationEmailNotRenewedB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-02-01')
        );
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();

        // Should be the cancellation email and notification about pot reduction
        $cancellationEmail = $policyA->getUser()->getEmail();
        $potReductionEmail = $policyB->getUser()->getEmail();

        $this->expectPotRewardEmail($mailer, 0, $potReductionEmail);
        $this->expectExpirationEmail($mailer, 1, $cancellationEmail);

        self::$policyService->setMailerMailer($mailer);

        self::$policyService->declineRenew($policyA, null, new \DateTime('2016-12-17'));
        self::$policyService->expire($policyA, new \DateTime('2017-01-01'));
    }

    /**
     * @group email
     */
    public function testPolicyCancellationEmailNotReconnected()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCancellationEmailNotReconnectedA', $this, true),
            static::generateEmail('testPolicyCancellationEmailNotReconnectedB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-02-01')
        );

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-20'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $policyA->getNextPolicy()->getStatus());

        $foundRenewalConnection = false;
        foreach ($policyA->getNextPolicy()->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        $policyRepo = static::$dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicyA */
        $updatedPolicyA = $policyRepo->find($policyA->getId());
        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyA->getStatus());

        /** @var Policy $updatedRenewalPolicyA */
        $updatedRenewalPolicyA = $policyRepo->find($policyA->getNextPolicy()->getId());
        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();

        // Should be the cancellation email and notification about pot reduction
        $cancellationEmail = $policyA->getUser()->getEmail();
        $potReductionEmail = $policyB->getUser()->getEmail();

        $this->expectPotRewardEmail($mailer, 0, $potReductionEmail);

        self::$policyService->setMailerMailer($mailer);

        static::$policyService->activate($updatedRenewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $updatedRenewalPolicyA->getStatus());
    }

    /**
     * @group email
     */
    public function testPolicyCancellationEmailReconnected()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCancellationEmailReconnectedA', $this, true),
            static::generateEmail('testPolicyCancellationEmailReconnectedB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-02-01')
        );

        static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-20'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $policyA->getNextPolicy()->getStatus());

        $foundRenewalConnection = false;
        foreach ($policyA->getNextPolicy()->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        $policyRepo = static::$dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicyA */
        $updatedPolicyA = $policyRepo->find($policyA->getId());
        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $updatedPolicyA->getStatus());

        /** @var Policy $updatedRenewalPolicyA */
        $updatedRenewalPolicyA = $policyRepo->find($policyA->getNextPolicy()->getId());
        $this->assertNotNull($policyB->getConnections()[0]->getLinkedPolicyRenewal());
        $this->assertTrue(count($updatedRenewalPolicyA->getAcceptedConnectionsRenewal()) > 0);

        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();

        // Should be the cancellation email and notification about pot reduction
        $cancellationEmail = $policyA->getUser()->getEmail();
        $potReductionEmail = $policyB->getUser()->getEmail();

        $mailer->expects($this->never())->method('send');
        self::$policyService->setMailerMailer($mailer);

        static::$policyService->activate($updatedRenewalPolicyA, new \DateTime('2017-01-02'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $updatedRenewalPolicyA->getStatus());
    }

    public function testPolicyConnectionReduction()
    {
        /** @var Policy $policyA */
        /** @var Policy $policyB */
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewalConnectionA', $this, true),
            static::generateEmail('testPolicyRenewalConnectionB', $this, true),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-15')
        );
        $connection = $policyA->getConnections()[0];
        $connection->setValue(5);
        $this->assertTrue(self::$policyService->connectionReduced($connection));

        self::$policyService->cancel($policyB, Policy::CANCELLED_UPGRADE);
        $this->assertFalse(self::$policyService->connectionReduced($connection));
    }

    public function testValidatePremiumWithoutAmount()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidatePremiumWithoutAmount', $this, true),
            'bar',
            null,
            static::$dm
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $this->assertEquals(
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            $policy->getPremium()->getMonthlyPremiumPrice()
        );

        $validFrom = \DateTime::createFromFormat('U', time());
        $validFrom->sub(new \DateInterval('PT1H'));

        $currentPrice = $phone->getCurrentPhonePrice();

        $price = new PhonePrice();
        $price->setGwp($phone->getCurrentPhonePrice()->getGwp()+1);
        $price->setValidFrom($validFrom);
        $phone->addPhonePrice($price);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(
            $price->getMonthlyPremiumPrice(),
            $updatedPolicy->getPremium()->getMonthlyPremiumPrice()
        );
        $this->assertNull($updatedPolicy->getStatus());
    }

    public function testValidatePremiumAmountMonthlyInside()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidatePremiumAmountMonthlyInside', $this, true),
            'bar',
            null,
            static::$dm
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $this->assertEquals(
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            $policy->getPremium()->getMonthlyPremiumPrice()
        );

        $policy->setPremiumInstallments(12);

        $validFrom = \DateTime::createFromFormat('U', time());
        $validFrom->sub(new \DateInterval('PT10M'));

        $currentPrice = $phone->getCurrentPhonePrice();

        $price = new PhonePrice();
        $price->setGwp($phone->getCurrentPhonePrice()->getGwp()+1);
        $price->setValidFrom($validFrom);
        $phone->addPhonePrice($price);

        $payment = new JudoPayment();
        $payment->setAmount($price->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setSuccess(true);
        $policy->addPayment($payment);
        static::$policyService->create($policy);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(
            $price->getMonthlyPremiumPrice(),
            $updatedPolicy->getPremium()->getMonthlyPremiumPrice()
        );
    }

    public function testValidatePremiumAmountYearlyInside()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidatePremiumAmountYearlyInside', $this, true),
            'bar',
            null,
            static::$dm
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $this->assertEquals(
            $phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            $policy->getPremium()->getYearlyPremiumPrice()
        );

        $policy->setPremiumInstallments(1);

        $validFrom = \DateTime::createFromFormat('U', time());
        $validFrom->sub(new \DateInterval('PT10M'));

        $currentPrice = $phone->getCurrentPhonePrice();

        $price = new PhonePrice();
        $price->setGwp($phone->getCurrentPhonePrice()->getGwp()+1);
        $price->setValidFrom($validFrom);
        $phone->addPhonePrice($price);

        $payment = new JudoPayment();
        $payment->setAmount($price->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setSuccess(true);
        $policy->addPayment($payment);
        static::$policyService->create($policy);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(
            $price->getYearlyPremiumPrice(),
            $updatedPolicy->getPremium()->getYearlyPremiumPrice()
        );
    }

    public function testValidatePremiumAmountMonthlyOutsidePast()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidatePremiumAmountMonthlyOutsidePast', $this, true),
            'bar',
            null,
            static::$dm
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $this->assertEquals(
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            $policy->getPremium()->getMonthlyPremiumPrice()
        );

        $policy->setPremiumInstallments(12);

        $validFrom = \DateTime::createFromFormat('U', time());
        $validFrom->sub(new \DateInterval('PT1H'));

        $currentPrice = $phone->getCurrentPhonePrice();

        $price = new PhonePrice();
        $price->setGwp($phone->getCurrentPhonePrice()->getGwp()+1);
        $price->setValidFrom($validFrom);
        $phone->addPhonePrice($price);

        $payment = new JudoPayment();
        $payment->setAmount($price->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setSuccess(true);
        $policy->addPayment($payment);
        static::$policyService->create($policy);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(
            $price->getMonthlyPremiumPrice(),
            $updatedPolicy->getPremium()->getMonthlyPremiumPrice()
        );
    }

    public function testValidatePremiumAmountYearlyOutsidePast()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidatePremiumAmountYearlyOutsidePast', $this, true),
            'bar',
            null,
            static::$dm
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $this->assertEquals(
            $phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            $policy->getPremium()->getYearlyPremiumPrice()
        );

        $policy->setPremiumInstallments(1);

        $validFrom = \DateTime::createFromFormat('U', time());
        $validFrom->sub(new \DateInterval('PT1H'));

        $currentPrice = $phone->getCurrentPhonePrice();

        $price = new PhonePrice();
        $price->setGwp($phone->getCurrentPhonePrice()->getGwp()+1);
        $price->setValidFrom($validFrom);
        $phone->addPhonePrice($price);

        $payment = new JudoPayment();
        $payment->setAmount($price->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setSuccess(true);
        $policy->addPayment($payment);
        static::$policyService->create($policy);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(
            $price->getYearlyPremiumPrice(),
            $updatedPolicy->getPremium()->getYearlyPremiumPrice()
        );
    }

    public function testValidatePremiumAmountMonthlyOutsideFuture()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidatePremiumAmountMonthlyOutsideFuture', $this, true),
            'bar',
            null,
            static::$dm
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $this->assertEquals(
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            $policy->getPremium()->getMonthlyPremiumPrice()
        );

        $policy->setPremiumInstallments(12);

        $validFrom = \DateTime::createFromFormat('U', time());
        $validFrom->add(new \DateInterval('PT3H'));

        $currentPrice = $phone->getCurrentPhonePrice();

        $price = new PhonePrice();
        $price->setGwp($phone->getCurrentPhonePrice()->getGwp()+1);
        $price->setValidFrom($validFrom);
        $phone->addPhonePrice($price);

        $payment = new JudoPayment();
        $payment->setAmount($currentPrice->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setSuccess(true);
        $policy->addPayment($payment);
        static::$policyService->create($policy);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(
            $currentPrice->getMonthlyPremiumPrice(),
            $updatedPolicy->getPremium()->getMonthlyPremiumPrice()
        );
    }

    public function testValidatePremiumAmountYearlyOutsideFuture()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidatePremiumAmountYearlyOutsideFuture', $this, true),
            'bar',
            null,
            static::$dm
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $this->assertEquals(
            $phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            $policy->getPremium()->getYearlyPremiumPrice()
        );

        $policy->setPremiumInstallments(1);

        $validFrom = \DateTime::createFromFormat('U', time());
        $validFrom->add(new \DateInterval('PT3H'));

        $currentPrice = $phone->getCurrentPhonePrice();

        $price = new PhonePrice();
        $price->setGwp($phone->getCurrentPhonePrice()->getGwp()+1);
        $price->setValidFrom($validFrom);
        $phone->addPhonePrice($price);

        $payment = new JudoPayment();
        $payment->setAmount($currentPrice->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setSuccess(true);
        $policy->addPayment($payment);
        static::$policyService->create($policy);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(
            $currentPrice->getYearlyPremiumPrice(),
            $updatedPolicy->getPremium()->getYearlyPremiumPrice()
        );
    }

    /**
     * @expectedException \Exception
     */
    public function testValidatePremiumDifferentAmountMonthly()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidatePremiumDifferentAmountMonthly', $this, true),
            'bar',
            null,
            static::$dm
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $this->assertEquals(
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            $policy->getPremium()->getMonthlyPremiumPrice()
        );

        $policy->setPremiumInstallments(12);

        $validFrom = \DateTime::createFromFormat('U', time());
        $validFrom->sub(new \DateInterval('PT1H'));

        $currentPrice = $phone->getCurrentPhonePrice();

        $price = new PhonePrice();
        $price->setGwp($phone->getCurrentPhonePrice()->getGwp()+1);
        $price->setValidFrom($validFrom);
        $phone->addPhonePrice($price);

        $payment = new JudoPayment();
        $payment->setAmount($price->getMonthlyPremiumPrice()+1);
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setSuccess(true);
        $policy->addPayment($payment);
        static::$policyService->create($policy);
    }

    /**
     * @expectedException \Exception
     */
    public function testValidatePremiuDifferentAmountYearly()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testValidatePremiuDifferentAmountYearly', $this, true),
            'bar',
            null,
            static::$dm
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone);

        $this->assertEquals(
            $phone->getCurrentPhonePrice()->getYearlyPremiumPrice(),
            $policy->getPremium()->getYearlyPremiumPrice()
        );

        $policy->setPremiumInstallments(1);

        $validFrom = \DateTime::createFromFormat('U', time());
        $validFrom->sub(new \DateInterval('PT1H'));

        $currentPrice = $phone->getCurrentPhonePrice();

        $price = new PhonePrice();
        $price->setGwp($phone->getCurrentPhonePrice()->getGwp()+1);
        $price->setValidFrom($validFrom);
        $phone->addPhonePrice($price);

        $payment = new JudoPayment();
        $payment->setAmount($price->getYearlyPremiumPrice()+10);
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $payment->setSuccess(true);
        $policy->addPayment($payment);
        static::$policyService->create($policy);
    }

    /**
     * @expectedException \AppBundle\Exception\DuplicateImeiException
     */
    public function testCreateWithDuplicateImei()
    {
        $imei = static::generateRandomImei();

        $userA = static::createUser(
            static::$userManager,
            static::generateEmail('testCreateWithDuplicateImeiA', $this, true),
            'bar',
            static::$dm
        );
        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('testCreateWithDuplicateImeiB', $this, true),
            'bar',
            static::$dm
        );
        $policyA = static::initPolicy(
            $userA,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true,
            false,
            true,
            $imei
        );
        self::setPaymentMethodForPolicy($policyA);
        $policyA->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policyA, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $policyB = static::initPolicy(
            $userB,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true,
            false,
            true,
            $imei
        );
        $policyB->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policyB, new \DateTime('2016-01-01'), true);
    }

    /**
     * @expectedException \Exception Unable to create a pending renewal for policy
     * @throws \Exception
     */
    public function testCannotRenewBlacklistedUser()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCannotRenewBlacklistedUser', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $thisUser = $policy->getUser();
        $thisUser->setIsBlacklisted(true);
        static::$dm->flush();

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('test');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());


        try {
            static::$policyService->createPendingRenewal(
                $policy,
                new \DateTime('2016-12-15')
            );
        } catch (\Exception $e) {
            return;
        }
    }

    public function testPolicyCreationOnBacsSchedulesPaymentCorrectly()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail(
                "testPolicyCreationOnBacsSchedulesPaymentCorrectly",
                $this,
                true
            ),
            "foo"
        );

        $policy = self::initPolicy(
            $user,
            self::$dm,
            self::getRandomPhone(self::$dm),
            null,
            false,
            true
        );

        $policy->setPaymentMethod(new BacsPaymentMethod());
        $policy->setPremiumInstallments(12);
        self::$dm->flush();
        self::$policyService->generateScheduledPayments($policy);
        $scheduledPayments = $policy->getScheduledPayments();
        foreach ($scheduledPayments as $scheduledPayment) {
            $checkDate = $scheduledPayment->getScheduled()->format('Ymd');
            $this->assertFalse(in_array($checkDate, $this->getNonWorkingDays()));
        }
        $this->assertEquals(12, count($scheduledPayments));
    }

    public function testPolicyCreationOnBacsSchedulesPaymentCorrectlyWithBankHoliday()
    {
        $today = new \DateTime();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail(
                "testPolicyCreationOnBacsSchedulesPaymentCorrectlyWithBankHoliday",
                $this,
                true
            ),
            "foo"
        );

        $dateFromNextBankHoliday = new \DateTime(sprintf(
            "%s%s%s",
            $today->format('Y'),
            $today->format('m'),
            $this->getNextBankHolidayAfterDate()->format('d')
        ));

        $policy = self::initPolicy(
            $user,
            self::$dm,
            self::getRandomPhone(self::$dm),
            $today,
            false,
            true,
            true,
            null,
            $dateFromNextBankHoliday
        );

        $policy->setPaymentMethod(new BacsPaymentMethod());
        $policy->setPremiumInstallments(12);
        self::$dm->flush();
        self::$policyService->generateScheduledPayments($policy);
        $scheduledPayments = $policy->getScheduledPayments();
        foreach ($scheduledPayments as $scheduledPayment) {
            $checkDate = $scheduledPayment->getScheduled()->format('Ymd');
            $this->assertFalse(in_array($checkDate, $this->getNonWorkingDays()));
        }
        $this->assertEquals(12, count($scheduledPayments));
    }

    public function testPolicyRenewBillingDateMigrates()
    {
        $yesterday = new \DateTime();
        $yesterday->sub(new \DateInterval("P1D"));
        $start = new \DateTime();
        $start->sub(new \DateInterval("P1Y1D"));
        $billing = clone $start;
        $billing->add(new \DateInterval("P14D"));
        $renewalStart = new \DateTime();
        $renewalStart->sub(new \DateInterval("P14D"));
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyRenewBillingDateMigrates', $this, true),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $start
        );

        $policy->setPaymentMethod(new BacsPaymentMethod());
        $policy->getBacsPaymentMethod()->setBankAccount(new BankAccount());
        $policy->setPremiumInstallments(12);
        self::$dm->flush();

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create(
            $policy,
            $start,
            true,
            12,
            null,
            $billing
        );
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $policy->getStart()->getTimeZone());

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            $renewalStart
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        static::$policyService->renew(
            $policy,
            12,
            null,
            false,
            $renewalStart
        );
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicy->getStatus());
        self::$policyService->expire($policy, $yesterday);
        self::$policyService->activate($renewalPolicy, $yesterday);
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicy->getStatus());

        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $renewalPolicy->getStart()->getTimeZone());

        $this->assertEquals($policy->getBilling()->add(new \DateInterval("P1Y")), $renewalPolicy->getBilling());
    }

    /**
     * Makes sure that start of state finds the start of the state and the correct one when there are many of them.
     * Even if the dates are in the past.
     */
    public function testCancelOverduePicsurePolicies()
    {
        $date = new \DateTime();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = static::$dm->getRepository(Policy::class);
        /** @var LogEntryRepository $logEntryRepo */
        $logEntryRepo = static::$dm->getRepository(LogEntry::class);
        $user = Create::user();
        $a = Create::policy($user, $date, Policy::STATUS_PICSURE_REQUIRED, 12);
        $b = Create::policy($user, $date, Policy::STATUS_PICSURE_REQUIRED, 12);
        $c = Create::policy($user, $date, Policy::STATUS_UNPAID, 12);
        Create::save(static::$dm, $user, $a, $b, $c);
        // Don't want creation logs as they are at the current date and time.
        $logEntryRepo->createQueryBuilder()->remove()->getQuery()->execute();
        // create new update logs.
        Create::save(
            static::$dm,
            Create::logEntry($a, Policy::STATUS_PICSURE_REQUIRED, 12),
            Create::logEntry($a, Policy::STATUS_UNPAID, 51),
            Create::logEntry($a, Policy::STATUS_PICSURE_REQUIRED, 60),
            Create::logEntry($a, Policy::STATUS_ACTIVE, 39),
            Create::logEntry($b, Policy::STATUS_PICSURE_REQUIRED, 30),
            Create::logEntry($c, Policy::STATUS_PICSURE_REQUIRED, 30),
            Create::logEntry($c, Policy::STATUS_UNPAID, 31)
        );
        // Make sure a dry run is dry.
        $cancelled = static::$policyService->cancelOverduePicsurePolicies(true);
        Create::refresh(static::$dm, $a, $b, $c);
        $this->assertEquals(Policy::STATUS_PICSURE_REQUIRED, $a->getStatus());
        $this->assertEquals(Policy::STATUS_PICSURE_REQUIRED, $b->getStatus());
        $this->assertEquals(Policy::STATUS_UNPAID, $c->getStatus());
        // Now do a wet run
        $cancelledAgain = static::$policyService->cancelOverduePicsurePolicies(false);
        Create::refresh(static::$dm, $a, $b, $c);
        $this->assertEquals(Policy::STATUS_PICSURE_REQUIRED, $a->getStatus());
        $this->assertEquals(Policy::STATUS_CANCELLED, $b->getStatus());
        $this->assertEquals(Policy::CANCELLED_PICSURE_REQUIRED_EXPIRED, $b->getCancelledReason());
        $this->assertEquals(Policy::STATUS_UNPAID, $c->getStatus());
        // look at the cancelled lists.
        $this->assertEquals($cancelled, $cancelledAgain);
        $this->assertArrayHasKey($b->getId(), $cancelled);
        $this->assertContains($b->getPolicyNumber(), $cancelled);
        $this->assertEquals(1, count($cancelled));
    }

    /**
     * Tests that check owed premium counts owed premium both from rescheduled payments and from normal scheduled
     * calculation and does not over report and makes policies that are truly up to date active instead of unpaid, and
     * should do nothing when the given policy is not in the unpaid status.
     */
    public function testCheckOwedPremium()
    {
        // Create test data.
        $user = Create::user();
        $a = Create::policy($user, "2019-01-01", Policy::STATUS_UNPAID, 12);
        $b = Create::policy($user, "2019-02-04", Policy::STATUS_UNPAID, 12);
        $c = Create::policy($user, "2019-01-01", Policy::STATUS_UNPAID, 12);
        $d = Create::policy($user, "2019-01-01", Policy::STATUS_ACTIVE, 12);
        Create::save(static::$dm, $user, $a, $b, $c, $d);
        Create::save(
            static::$dm,
            Create::standardPayment($a, "2019-01-01", true),
            Create::standardPayment($b, "2019-02-04", true),
            Create::standardPayment($b, "2019-03-04", true),
            Create::standardPayment($b, "2019-04-04", true),
            Create::standardScheduledPayment(
                $b,
                "2019-05-10",
                ScheduledPayment::STATUS_SCHEDULED,
                ScheduledPayment::TYPE_RESCHEDULED
            ),
            Create::standardScheduledPayment(
                $b,
                "2019-04-10",
                ScheduledPayment::STATUS_CANCELLED,
                ScheduledPayment::TYPE_RESCHEDULED
            ),
            Create::standardPayment($c, "2019-01-01", true),
            Create::standardPayment($c, "2019-02-01", true)
        );
        // Run on the policies.
        $resultA = static::$policyService->checkOwedPremium($a, new \DateTime("2019-03-22"));
        $resultB = static::$policyService->checkOwedPremium($b, new \DateTime("2019-05-03"));
        $resultC = static::$policyService->checkOwedPremium($c, new \DateTime("2019-02-15"));
        $resultD = static::$policyService->checkOwedPremium($d, new \DateTime("2019-05-12"));
        // Check the numeric results.
        $this->assertEquals(2 * $a->getPremium()->getMonthlyPremiumPrice(), $resultA);
        $this->assertEquals($b->getPremium()->getMonthlyPremiumPrice(), $resultB);
        $this->assertEquals(0, $resultC);
        $this->assertEquals(0, $resultD);
        // Check the statuses.
        Create::refresh(static::$dm, $a, $b, $c, $d);
        $this->assertEquals(Policy::STATUS_UNPAID, $a->getStatus());
        $this->assertEquals(Policy::STATUS_UNPAID, $b->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $c->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $d->getStatus());
        // Also check that the results of this function coincide with the results of the self contained version except
        // for in the case of the already active policy.
        $this->assertEquals($a->getOutstandingPremiumToDateWithReschedules(new \DateTime("2019-03-22")), $resultA);
        $this->assertEquals($b->getOutstandingPremiumToDateWithReschedules(new \DateTime("2019-05-03")), $resultB);
        $this->assertEquals($c->getOutstandingPremiumToDateWithReschedules(new \DateTime("2019-02-15")), $resultC);
        $this->assertEquals(
            $d->getPremium()->getMonthlyPremiumPrice() * 5,
            $d->getOutstandingPremiumToDateWithReschedules(new \DateTime("2019-05-12"))
        );
    }

    private function getFormattedWeekendsForOneYear($fromDate = null)
    {
        if (!$fromDate) {
            $fromDate = new \DateTime();
        }
        $weekends = [];
        $endDate = new \DateTime();
        $endDate->add(new \DateInterval("P1Y"));

        $period = new \DatePeriod($fromDate, new \DateInterval("P1D"), $endDate);

        foreach ($period as $day) {
            if (in_array($day->format('N'), [6, 7])) {
                $weekends[] = $day->format('Ymd');
            }
        }
        return $weekends;
    }

    private function getFormattedBankHolidays()
    {
        $bankHolidays = [];
        foreach (static::getBankHolidays() as $bankHoliday) {
            if (!$bankHoliday instanceof \DateTime) {
                $bankHoliday = new \DateTime($bankHoliday);
            }
            $bankHolidays[] = $bankHoliday->format('Ymd');
        }
        return $bankHolidays;
    }

    private function getNonWorkingDays()
    {
        return array_merge($this->getFormattedWeekendsForOneYear(), $this->getFormattedBankHolidays());
    }

    private function getNextBankHolidayAfterDate($fromDate = null)
    {
        if (!$fromDate) {
            $fromDate = new \DateTime();
        }
        foreach (static::getBankHolidays() as $bankHoliday) {
            if ($bankHoliday > $fromDate) {
                return $bankHoliday;
            }
        }
        return "No bank holidays known after date";
    }
}
