<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyKeyFacts;
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
        static::$policyService->create($policy, $user);

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
        static::$policyService->create($policy, $user);

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
        static::$policyService->create($policy, $user);

        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertTrue($updatedPolicy->isPolicy(), 'Policy must have a status');
        $this->assertFalse($updatedPolicy->isValidPolicy());
        $this->assertTrue(stripos($updatedPolicy->getPolicyNumber(), 'INVALID/') !== false);
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
        static::$policyService->create($policy, $user);
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
        $policy->addPayment($payment);

        static::$policyService->create($policy, $user);

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
            $policy->addPayment($payment);

            static::$policyService->create($policy, $user, $date);

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
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $policy->addPayment($payment);

        static::$policyService->create($policy, $user);
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
        $policy->addPayment($payment);

        static::$policyService->create($policy, $user);

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
        static::$policyService->create($policy, $user);
    }
}
