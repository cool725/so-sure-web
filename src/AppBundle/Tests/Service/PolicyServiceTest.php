<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\GocardlessPayment;
use AppBundle\Document\SCode;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Phone;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Service\InvitationService;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Classes\Salva;
use AppBundle\Service\SalvaExportService;

/**
 * @group functional-nonet
 */
class PolicyServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
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
            $policy->addPayment($payment);

            static::$policyService->create($policy, $date);

            $updatedPolicy = static::$policyRepo->find($policy->getId());
            $this->assertEquals(11, count($updatedPolicy->getScheduledPayments()));
            for ($i = 0; $i < 11; $i++) {
                $scheduledDate = $updatedPolicy->getScheduledPayments()[$i]->getScheduled();
                $this->assertEquals($expectedDay, $scheduledDate->format('d'));
            }
        }
    }

    /**
     * @expectedException \Exception
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

        static::$policyService->cancel($policy, PhonePolicy::CANCELLED_COOLOFF, false, new \DateTime('2016-01-10'));
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
        foreach ($policies as $policy) {
            if ($policy->getId() == $policyExpire->getId()) {
                $foundExpire = true;
            } elseif ($policy->getId() == $policyFuture->getId()) {
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
}
