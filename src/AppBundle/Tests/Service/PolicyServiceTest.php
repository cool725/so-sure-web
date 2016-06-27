<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\GocardlessPayment;
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
    }

    public function tearDown()
    {
    }

    public function testCancelPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('cancel', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm);
        static::$policyService->cancel($policy, Policy::CANCELLED_GOODWILL);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(Policy::STATUS_CANCELLED, $updatedPolicy->getStatus());
    }

    public function testCreatePolicyHasPromoCode()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('create', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->create($policy);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertEquals(Policy::PROMO_LAUNCH, $policy->getPromoCode());
    }

    public function testCreatePolicyPolicyNumber()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('create-policyNumber', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
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
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
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
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
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
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));

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
            'bar'
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

        $payment = new GocardlessPayment();
        $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
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
                'bar'
            );
            $phone = $this->getRandomPhone(static::$dm);
            $date = new \DateTime(sprintf('2016-01-%d', $actualDay));
            $policy = static::createPolicy($user, static::$dm, $phone, $date);

            $payment = new GocardlessPayment();
            $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
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
            'bar'
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

        $payment = new JudoPayment();
        $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
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
            'bar'
        );
        $phone = $this->getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

        $payment = new GocardlessPayment();
        $payment->setAmount($phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
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
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm, $this->getRandomPhone(static::$dm));
        static::$policyService->create($policy);
    }

    public function testSalvaCancelSimple()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-cancel', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');

        $policy->addPayment(new JudoPayment());

        static::$policyService->cancel($policy, PhonePolicy::CANCELLED_FRAUD, new \DateTime('2016-02-10'));
        $this->assertEquals(2, $policy->getPolicyLength());
        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice() * 2, $policy->getTotalPremiumPrice());
        $this->assertEquals($policy->getPremium()->getGwp() * 2, $policy->getTotalGwp());
        $this->assertEquals($policy->getPremium()->getIpt() * 2, $policy->getTotalIpt());
        $this->assertEquals(Salva::MONTHLY_TOTAL_COMMISSION * 2, $policy->getTotalBrokerFee());
    }

    public function testSalvaCooloff()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-cooloff', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');

        static::$policyService->cancel($policy, PhonePolicy::CANCELLED_COOLOFF, new \DateTime('2016-01-10'));
        $this->assertEquals(0, $policy->getTotalPremiumPrice());
        $this->assertEquals(0, $policy->getTotalGwp());
        $this->assertEquals(0, $policy->getTotalIpt());
        $this->assertEquals(0, $policy->getTotalBrokerFee());
    }

    public function testSalvaFullPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-full', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');

        for ($i = 1; $i <= 11; $i++) {
            $policy->addPayment(new JudoPayment());
        }

        $this->assertEquals($policy->getPremium()->getYearlyPremiumPrice(), $policy->getTotalPremiumPrice());
        $this->assertEquals($policy->getPremium()->getYearlyGwp(), $policy->getTotalGwp());
        $this->assertEquals($policy->getPremium()->getYearlyIpt(), $policy->getTotalIpt());
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $policy->getTotalBrokerFee());
    }

    public function testSalvaPartialPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-partial', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        static::$policyService->setEnvironment('test');

        $policy->addPayment(new JudoPayment());

        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice(), $policy->getTotalPremiumPrice([1]));
        $this->assertEquals($policy->getPremium()->getGwp(), $policy->getTotalGwp([1]));
        $this->assertEquals($policy->getPremium()->getIpt(), $policy->getTotalIpt([1]));
        $this->assertEquals(Salva::MONTHLY_TOTAL_COMMISSION, $policy->getTotalBrokerFee([1]));
    }
}
