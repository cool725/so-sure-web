<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Cashback;
use AppBundle\Document\Claim;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Payment\GocardlessPayment;
use AppBundle\Document\SCode;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Phone;
use AppBundle\Document\Connection\RenewalConnection;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Service\InvitationService;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\ValidationException;
use AppBundle\Classes\Salva;
use AppBundle\Service\SalvaExportService;

/**
 * @group functional-nonet
 */
class PolicyServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use \AppBundle\Document\DateTrait;

    protected static $container;
    protected static $policyService;
    protected static $dm;
    protected static $policyRepo;
    protected static $userManager;
    protected static $judopay;
    protected static $phone;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$policyRepo = self::$dm->getRepository(Policy::class);
         self::$userManager = self::$container->get('fos_user.user_manager');
         self::$policyService = self::$container->get('app.policy');
         self::$judopay = self::$container->get('app.judopay');

        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
    }

    public function tearDown()
    {
    }

    public function testCancelPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('cancel', $this),
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

    public function testCreatePolicyHasLaunchPromoCode()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCreatePolicyHasLaunchPromoCode', $this),
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
            static::generateEmail('testCreatePolicyHasNovPromoCode', $this),
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
            static::generateEmail('testCreatePolicyHasDec2016PromoCode', $this),
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
            static::generateEmail('create-policyNumber', $this),
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
            stripos($updatedPolicy->getPolicyNumber(), 'Mob/') !== false,
            'Policy number must contain Mob'
        );
    }

    public function testCreatePolicySoSurePolicyNumber()
    {
        $user = static::createUser(
            static::$userManager,
            'create-policyNumber@so-sure.com',
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
        $this->assertFalse($updatedPolicy->isValidPolicy());
        $this->assertTrue(stripos($updatedPolicy->getPolicyNumber(), 'INVALID/') !== false);
    }

    public function testCreatePolicyDuplicateCreate()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('create-dup', $this),
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
            stripos($updatedPolicy->getPolicyNumber(), 'Mob/') !== false,
            'Policy number must contain Mob'
        );
        $this->assertEquals(new \DateTime('2016-01-01'), $updatedPolicy->getStart());

        // Needs to be prod for a valid policy number, or create will affect policy times
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-02-01'));
        static::$policyService->setEnvironment('test');

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertTrue($updatedPolicy->isPolicy(), 'Policy must have a status');
        $this->assertTrue($updatedPolicy->isValidPolicy(), 'Policy must be valid');
        $this->assertTrue(
            stripos($updatedPolicy->getPolicyNumber(), 'Mob/') !== false,
            'Policy number must contain Mob'
        );
        $this->assertEquals(new \DateTime('2016-01-01'), $updatedPolicy->getStart());
    }

    /**
     * @expectedException AppBundle\Exception\InvalidPremiumException
     */
    public function testGenerateScheduledPaymentsInvalidAmount()
    {
        $user = static::createUser(
            static::$userManager,
            'scheduled-invalidamount@so-sure.com',
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));

        $payment = new GocardlessPayment();
        $payment->setAmount(0.01);
        $policy->addPayment($payment);
        static::$policyService->create($policy);
    }

    public function testGenerateScheduledPaymentsMonthlyPayments()
    {
        $user = static::createUser(
            static::$userManager,
            'scheduled-monthly@so-sure.com',
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));

        $payment = new GocardlessPayment();
        $payment->setAmount($policy->getPhone()->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $policy->addPayment($payment);

        static::$policyService->create($policy);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(11, count($updatedPolicy->getScheduledPayments()));
    }

    public function testGenerateScheduledPaymentsMonthlyPaymentsDates()
    {
        $dates = [
            28 => 28,
            29 => 28,
            31 => 28,
            1 => 1,
        ];
        foreach ($dates as $actualDay => $expectedDay) {
            $user = static::createUser(
                static::$userManager,
                sprintf('scheduled-monthly-%d@so-sure.com', $actualDay),
                'bar',
                null,
                static::$dm
            );
            $date = new \DateTime(sprintf('2016-01-%d', $actualDay));
            $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), $date);

            $payment = new GocardlessPayment();
            $payment->setAmount($policy->getPhone()->getCurrentPhonePrice($date)->getMonthlyPremiumPrice($date));
            $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
            $payment->setDate($date);
            $policy->addPayment($payment);

            static::$policyService->create($policy, $date);

            $updatedPolicy = static::$policyRepo->find($policy->getId());
            $this->assertEquals(11, count($updatedPolicy->getScheduledPayments()));
            for ($i = 0; $i < 11; $i++) {
                $scheduledDate = $updatedPolicy->getScheduledPayments()[$i]->getScheduled();
                $this->assertEquals($expectedDay, $scheduledDate->format('d'));
                $this->assertTrue($scheduledDate->diff($policy->getStart())->days >= 28);
            }
        }
    }

    /**
     * @expectedException AppBundle\Exception\InvalidPremiumException
     */
    public function testGenerateScheduledPaymentsFailedMonthlyPayments()
    {
        $user = static::createUser(
            static::$userManager,
            'scheduled-failed-monthly@so-sure.com',
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

    public function testGenerateScheduledPaymentsYearlyPayment()
    {
        $user = static::createUser(
            static::$userManager,
            'scheduled-yearly@so-sure.com',
            'bar',
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));

        $payment = new GocardlessPayment();
        $payment->setAmount($policy->getPhone()->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $policy->addPayment($payment);

        static::$policyService->create($policy);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(0, count($updatedPolicy->getScheduledPayments()));
    }

    /**
     * @expectedException \Exception
     */
    public function testGenerateScheduledPaymentsMissingPayment()
    {
        $user = static::createUser(
            static::$userManager,
            'scheduled-missing@so-sure.com',
            'bar',
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));
        static::$policyService->create($policy);
    }

    public function testSalvaCancelSimple()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-cancel', $this),
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
            static::generateEmail('testAdjustScheduledPayments', $this),
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

        //static::addPayment($policy, $policy->getPremium()->getMonthlyPremiumPrice(), Salva::MONTHLY_TOTAL_COMMISSION);

        // Initial payment applied - nothing to adjust
        $this->assertNull(self::$policyService->adjustScheduledPayments($policy));
        $this->assertEquals(0, count($policy->getAllScheduledPayments(ScheduledPayment::STATUS_CANCELLED)));
    }

    public function testAdjustScheduledPaymentsAdditionalPayment()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testAdjustScheduledPaymentsAdditionalPayment', $this),
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
        static::addPayment($policy, $policy->getPremium()->getMonthlyPremiumPrice(), Salva::MONTHLY_TOTAL_COMMISSION);

        // 1 scheduled payment should be cancelled to offset the additional payment received
        $this->assertTrue(self::$policyService->adjustScheduledPayments($policy));
        $this->assertEquals(1, count($policy->getAllScheduledPayments(ScheduledPayment::STATUS_CANCELLED)));
    }

    public function testAdjustScheduledPaymentsLaterDate()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testAdjustScheduledPaymentsLaterDate', $this),
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
            static::generateEmail('testPolicyCancelledTooEarly', $this),
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

        $this->assertEquals(new \DateTime('2017-03-30'), $policy->getPolicyExpirationDate());

        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2017-02-28')
        );
        $this->assertEquals(new \DateTime('2017-04-27'), $policy->getPolicyExpirationDate());

        // in previous case, payment was on 16/4, which was after the change in billing date
        // and cause the problem. as exception will prevent, no point in testing that case here
        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            new \DateTime('2017-04-12')
        );
        $this->assertEquals(new \DateTime('2017-05-28'), $policy->getPolicyExpirationDate());
        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2017-04-20')));

        $billingDate = $this->setDayOfMonth($policy->getBilling(), '15');
        $policy->setBilling($billingDate, new \DateTime('2017-04-20'));

        $this->assertTrue(self::$policyService->adjustScheduledPayments($policy));
        $scheduledPayments = $policy->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);
        $this->assertEquals(15, $scheduledPayments[0]->getScheduledDay());

        $this->assertEquals(new \DateTime('2017-05-15'), $policy->getPolicyExpirationDate());
    }

    /**
     * @expectedException \Exception
     */
    public function testPolicyUnpaidUnableToChangeBilling()
    {
        $pastDue = new \DateTime();
        $pastDue = $pastDue->sub(new \DateInterval('P35D'));
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyUnpaidUnableToChangeBilling', $this),
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

        $now = new \DateTime();
        $billingDate = $this->setDayOfMonth($now, '15');
        $policy->setBilling($billingDate);
    }

    public function testAdjustScheduledPaymentsEarlierDate()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testAdjustScheduledPaymentsEarlierDate', $this),
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
            static::generateEmail('testUnableToAdjustScheduledPayments', $this),
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
        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice()  + 0.01,
            Salva::MONTHLY_TOTAL_COMMISSION
        );

        $this->assertFalse(self::$policyService->adjustScheduledPayments($policy));
        $this->assertEquals(0, count($policy->getAllScheduledPayments(ScheduledPayment::STATUS_CANCELLED)));
    }

    public function testSalvaCooloff()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-cooloff', $this),
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
            static::generateEmail('salva-full', $this),
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
                $policy->getPremium()->getMonthlyPremiumPrice(),
                Salva::MONTHLY_TOTAL_COMMISSION
            );
        }
        static::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::FINAL_MONTHLY_TOTAL_COMMISSION
        );

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
            static::generateEmail('testSalvaFullPolicyGwpDiff', $this),
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
            static::addPayment(
                $policy,
                $policy->getPremium()->getMonthlyPremiumPrice(),
                Salva::MONTHLY_TOTAL_COMMISSION
            );
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
            static::generateEmail('salva-partial', $this),
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
            static::generateEmail('scode', $this),
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
            static::generateEmail('testScodeMultiplePolicy', $this),
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

    public function testValidatePremiumIptRateChange()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('vpreium-rate', $this),
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
            static::generateEmail('vpreium-normal', $this),
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

    public function testWeeklyEmail()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('weekly', $this),
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

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-10-01'));
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        
        $this->assertTrue(static::$policyService->weeklyEmail($policy));
        
        $optout = new EmailOptOut();
        $optout->setCategory(EmailOptOut::OPTOUT_CAT_WEEKLY);
        $optout->setEmail(static::generateEmail('weekly', $this));
        static::$dm->persist($optout);
        static::$dm->flush();

        $this->assertNull(static::$policyService->weeklyEmail($policy));
    }

    public function testPoliciesPendingCancellation()
    {
        $yesterday = new \DateTime();
        $yesterday = $yesterday->sub(new \DateInterval('P1D'));
        $now = new \DateTime();
        $tomorrow = new \DateTime();
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));

        $userFuture = static::createUser(
            static::$userManager,
            static::generateEmail('testPoliciesPendingCancellation-future', $this),
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
            static::generateEmail('testPoliciesPendingCancellation-expire', $this),
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
            static::generateEmail('testCreatePolicyBacs', $this),
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
            static::generateEmail('testPolicyExpire', $this),
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
            static::generateEmail('testPolicyRenew', $this),
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

        static::$policyService->renew($policy, 12, null, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicy->getStatus());
        $this->assertNull($policy->getCashback());
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
            static::generateEmail('testCreatePendingRenewalPolicies', $this),
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

    public function testPolicyRenewCashback()
    {
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRenewCashbackA', $this),
            static::generateEmail('testPolicyRenewCashbackB', $this)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        $cashback = $this->getCashback($policyA);
        $cashback->setAccountName('a');
        $exceptionThrown = false;
        try {
            static::$policyService->renew($policyA, 12, $cashback, new \DateTime('2016-12-30'));
        } catch (ValidationException $e) {
            $exceptionThrown = true;
        }
        $this->assertTrue($exceptionThrown);

        $cashback = $this->getCashback($policyA);
        $cashback->setSortCode('1');
        $exceptionThrown = false;
        try {
            static::$policyService->renew($policyA, 12, $cashback, new \DateTime('2016-12-30'));
        } catch (ValidationException $e) {
            $exceptionThrown = true;
        }
        $this->assertTrue($exceptionThrown);

        $cashback = $this->getCashback($policyA);
        $cashback->setAccountNumber('1');
        $exceptionThrown = false;
        try {
            static::$policyService->renew($policyA, 12, $cashback, new \DateTime('2016-12-30'));
        } catch (ValidationException $e) {
            $exceptionThrown = true;
        }
        $this->assertTrue($exceptionThrown);

        $cashback = $this->getCashback($policyA);
        static::$policyService->renew($policyA, 12, $cashback, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertNotNull($policyA->getCashback());
        $this->assertEquals(Cashback::STATUS_PENDING_CLAIMABLE, $policyA->getCashback()->getStatus());
        
        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());
        $this->assertEquals(Cashback::STATUS_PENDING_CLAIMABLE, $policyA->getCashback()->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-01'));
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
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCashbackThenRenewA', $this),
            static::generateEmail('testPolicyCashbackThenRenewB', $this)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        $cashback = $this->getCashback($policyA);
        static::$policyService->cashback($policyA, $cashback);

        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());

        $this->assertEquals($cashback->getAccountNumber(), $policyA->getCashback()->getAccountNumber());
        $this->assertEquals(Cashback::STATUS_PENDING_CLAIMABLE, $policyA->getCashback()->getStatus());

        static::$policyService->renew($policyA, 12, null, new \DateTime('2016-12-30'));
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
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCashbackThenRenewWithCashbackA', $this),
            static::generateEmail('testPolicyCashbackThenRenewWithCashbackB', $this)
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
        static::$policyService->renew($updatedPolicyA, 12, $cashback, new \DateTime('2016-12-30'));
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
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCashbackThenRenewWithDiscountA', $this),
            static::generateEmail('testPolicyCashbackThenRenewWithDiscountB', $this)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        $cashback = $this->getCashback($policyA);
        static::$policyService->cashback($policyA, $cashback);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $updatedPolicyA = $policyRepo->find($policyA->getId());

        $this->assertEquals($cashback->getAccountNumber(), $updatedPolicyA->getCashback()->getAccountNumber());

        static::$policyService->renew($updatedPolicyA, 12, null, new \DateTime('2016-12-30'));
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
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyCashbackUnrenewA', $this),
            static::generateEmail('testPolicyCashbackUnrenewB', $this)
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

    public function testPolicyUnrenew()
    {
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyUnrenewA', $this),
            static::generateEmail('testPolicyUnrenewB', $this)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->unrenew($renewalPolicyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_UNRENEWED, $renewalPolicyA->getStatus());
        $this->assertEquals(Cashback::STATUS_MISSING, $policyA->getCashback()->getStatus());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
    }

    public function testPolicyRepurchase()
    {
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyRepurchaseA', $this),
            static::generateEmail('testPolicyRepurchaseB', $this)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

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
            $newPolicyA->getPremium(new \DateTime('2017-01-01'))->getMonthlyPremiumPrice(),
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

    private function getCashback($policy)
    {
        $cashback = new Cashback();
        $cashback->setDate(new \DateTime());
        $cashback->setStatus(Cashback::STATUS_PENDING_CLAIMABLE);
        $cashback->setAccountName('foo');
        $cashback->setSortCode('123456');
        $cashback->setAccountNumber('12345678');
        $cashback->setPolicy($policy);

        return $cashback;
    }

    public function testPolicyActive()
    {
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyActiveA', $this),
            static::generateEmail('testPolicyActiveB', $this),
            false
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();

        static::$policyService->renew($policyA, 12, null, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-01'));
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

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
    }

    public function getPendingRenewalPolicies($emailA, $emailB, $connect = true)
    {
        $userA = static::createUser(
            static::$userManager,
            $emailA,
            'bar',
            static::$dm
        );
        $userB = static::createUser(
            static::$userManager,
            $emailB,
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

        if ($connect) {
            list($connectionA, $connectionB) = $this->createLinkedConnections($policyA, $policyB, 10, 10);
        }

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

        return [$policyA, $policyB];
    }

    public function testPolicyActiveWithConnections()
    {
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyActiveWithConnectionsA', $this),
            static::generateEmail('testPolicyActiveWithConnectionsB', $this)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->renew($policyA, 12, null, new \DateTime('2016-12-30'));
        static::$policyService->renew($policyB, 12, null, new \DateTime('2016-12-30'));
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

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-01'));
        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-01'));
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

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
    }

    public function testPolicyPaymentsRenewalWithConnections()
    {
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPolicyPaymentsRenewalWithConnectionsA', $this),
            static::generateEmail('testPolicyPaymentsRenewalWithConnectionsB', $this)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->renew($policyA, 12, null, new \DateTime('2016-12-30'));
        static::$policyService->renew($policyB, 12, null, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());
        $this->assertNull($renewalPolicyB->getPendingCancellation());

        $this->assertNull($policyA->getCashback());
        $this->assertNull($policyB->getCashback());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-01'));
        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-01'));
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
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testYearlyPolicyPaymentsRenewalWithConnectionsA', $this),
            static::generateEmail('testYearlyPolicyPaymentsRenewalWithConnectionsB', $this)
        );
        $renewalPolicyA = $policyA->getNextPolicy();
        $renewalPolicyB = $policyB->getNextPolicy();
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->renew($policyA, 1, null, new \DateTime('2016-12-30'));
        static::$policyService->renew($policyB, 1, null, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());
        $this->assertNull($renewalPolicyB->getPendingCancellation());

        $this->assertNull($policyA->getCashback());
        $this->assertNull($policyB->getCashback());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-01'));
        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $this->assertNotEquals(
            $renewalPolicyA->getPremium()->getYearlyPremiumPrice(),
            $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice()
        );
        $this->assertEquals(
            $renewalPolicyA->getPremium()->getAdjustedYearlyPremiumPrice(),
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
            static::generateEmail('testPotRewardWithClaimA', $this),
            'bar',
            static::$dm
        );
        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('testPotRewardWithClaimB', $this),
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

        static::$policyService->renew($policyA, 12, null, new \DateTime('2016-12-30'));
        static::$policyService->renew($policyB, 12, null, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());
        $this->assertNull($renewalPolicyB->getPendingCancellation());

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-01'));
        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-01'));
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

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyB->getStatus());

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
            10 + $paymentB->getAmount(),
            $policyB->getNextPolicy()->getTotalSuccessfulPayments(new \DateTime('2017-01-01'))
        );

        $claimA = new Claim();
        $claimA->setStatus(Claim::STATUS_SETTLED);
        $claimA->setType(Claim::TYPE_LOSS);
        $policyA->addClaim($claimA);

        static::$policyService->fullyExpire($policyA, new \DateTime('2017-01-29'));
        $this->assertEquals(Policy::STATUS_EXPIRED, $policyA->getStatus());
        // use policyA->getNextPolicy to avoid having to flush/reload from db
        $due = $policyA->getNextPolicy()->getPremium()->getYearlyPremiumPrice() -
            $paymentA->getAmount();
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
            static::generateEmail('testUpdateCashbackA', $this),
            'bar',
            static::$dm
        );
        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('testUpdateCashbackB', $this),
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
        $cashbackB->setDate(new \DateTime());
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
        $userA = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyActiveWithConnectionsNoReconnectA', $this),
            'bar',
            static::$dm
        );
        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyActiveWithConnectionsNoReconnectB', $this),
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
        $cashbackB->setDate(new \DateTime());
        $cashbackB->setStatus(Cashback::STATUS_PENDING_CLAIMABLE);
        $cashbackB->setAccountName('foo bar');
        $cashbackB->setSortCode('123456');
        $cashbackB->setAccountNumber('12345678');
        $cashbackB->setPolicy($policyB);
        static::$dm->persist($cashbackB);
        static::$dm->flush();

        static::$policyService->renew($policyA, 12, null, new \DateTime('2016-12-30'));
        static::$policyService->renew($policyB, 12, $cashbackB, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertNull($renewalPolicyA->getPendingCancellation());
        $this->assertNull($renewalPolicyB->getPendingCancellation());
        $this->assertTrue($policyA->isRenewed());
        $this->assertTrue($policyB->isRenewed());

        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());

        $foundRenewalConnection = false;
        foreach ($updatedRenewalPolicyA->getRenewalConnections() as $connection) {
            $this->assertTrue($connection->getRenew());
            $foundRenewalConnection = true;
            $connection->setRenew(false);
        }
        $this->assertTrue($foundRenewalConnection);
        static::$dm->flush();

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-01'));
        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-01-01'));
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

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));

        $this->assertTrue($policyA->isRenewed());
        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());
        $this->assertNull($policyA->getCashback());

        static::$policyService->expire($policyB, new \DateTime('2017-01-01'));

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
        $userA = static::createUser(
            static::$userManager,
            static::generateEmail('testNotReconnectedDoesNotAppearA', $this),
            'bar',
            static::$dm
        );
        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('testNotReconnectedDoesNotAppearB', $this),
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
            new \DateTime('2016-02-15'),
            true
        );

        $policyA->setStatus(PhonePolicy::STATUS_PENDING);
        $policyB->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policyA, new \DateTime('2016-01-01'), true);
        static::$policyService->create($policyB, new \DateTime('2016-02-15'), true);
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
            new \DateTime('2017-02-01')
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB->getStatus());

        static::$policyService->renew($policyA, 12, null, new \DateTime('2016-12-30'));
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

        static::$policyService->renew($policyB, 12, null, new \DateTime('2017-01-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertNull($renewalPolicyB->getPendingCancellation());

        $foundRenewalConnection = false;
        foreach ($updatedRenewalPolicyB->getRenewalConnections() as $connection) {
            $foundRenewalConnection = true;
        }
        $this->assertFalse($foundRenewalConnection);

        static::$policyService->activate($renewalPolicyA, new \DateTime('2017-01-01'));
        static::$policyService->activate($renewalPolicyB, new \DateTime('2017-02-15'));
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $renewalPolicyB->getStatus());

        $updatedRenewalPolicyA = $policyRepo->find($renewalPolicyA->getId());
        $updatedRenewalPolicyB = $policyRepo->find($renewalPolicyB->getId());
        $this->assertEquals(0, $updatedRenewalPolicyA->getPotValue());
        $this->assertEquals(0, $updatedRenewalPolicyB->getPotValue());
    }

    public function testSalvaRenewalCooloff()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSalvaRenewalCooloff', $this),
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

        static::$policyService->renew($policy, 12, null, new \DateTime('2016-12-25'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicy->getStatus());
        $this->assertNull($renewalPolicy->getPendingCancellation());

        static::$policyService->cancel(
            $renewalPolicy,
            PhonePolicy::CANCELLED_COOLOFF,
            false,
            false,
            new \DateTime('2016-12-30')
        );
        $this->assertEquals(0, $renewalPolicy->getTotalPremiumPrice());
        $this->assertEquals(0, $renewalPolicy->getTotalGwp());
        $this->assertEquals(0, $renewalPolicy->getUsedGwp());
        $this->assertEquals(0, $renewalPolicy->getTotalIpt());
        $this->assertEquals(0, $renewalPolicy->getTotalBrokerFee());
    }
}
