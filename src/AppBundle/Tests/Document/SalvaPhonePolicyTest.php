<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\User;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PolicyTerms;
use AppBundle\Event\PolicyEvent;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Tests\UserClassTrait;
use AppBundle\Classes\Salva;
use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * This test depends on the data fixtures having the phone price it expects which is not so good.
 * @group functional-nonet
 * @group fixed
 *
 * AppBundle\\Tests\\Document\\SalvaPhonePolicyTest
 */
class SalvaPhonePolicyTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use UserClassTrait;
    use DateTrait;

    protected static $container;
    protected static $phone6;
    protected static $judopay;
    /** @var DocumentManager */
    protected static $dm;

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
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');
        self::$judopay = self::$container->get('app.judopay');
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phone6 = $phoneRepo->findOneBy(['devices' => 'iPhone 6', 'memory' => 64]);
    }

    public function tearDown()
    {
    }

    public function testGetSalvaVersion()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setStart(new \DateTime('2016-01-01'));
        $this->assertNull($policy->getSalvaVersion(new \DateTime('2016-01-01')));
        
        $policy->incrementSalvaPolicyNumber(new \DateTime('2016-02-01'));
        $this->assertEquals(1, $policy->getSalvaVersion(new \DateTime('2016-01-01')));
        // current version is null
        $this->assertNull($policy->getSalvaVersion(new \DateTime('2016-02-01 00:01')));
    }

    public function testSalvaStatus()
    {
        $policy = static::createUserPolicy(true);
        $this->assertEquals(SalvaPhonePolicy::SALVA_STATUS_PENDING, $policy->getSalvaStatus());
    }

    public function testSalvaCancel()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('cancel', $this));
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->flush();
        $policy->cancel(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);
        $this->assertEquals(SalvaPhonePolicy::SALVA_STATUS_WAIT_CANCELLED, $policy->getSalvaStatus());
    }

    public function testIncrementSalvaPolicyNumber()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setStart(new \DateTime('2016-01-01'));
        $this->assertEquals(0, count($policy->getSalvaPolicyNumbers()));
        
        $policy->incrementSalvaPolicyNumber(new \DateTime('2016-02-01'));
        $this->assertEquals(1, count($policy->getSalvaPolicyNumbers()));

        $policy->incrementSalvaPolicyNumber(new \DateTime('2016-03-01'));
        $this->assertEquals(2, count($policy->getSalvaPolicyNumbers()));
    }

    public function testSalvaTerminationDate()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setStart(new \DateTime('2016-01-01'));
        $this->assertNull($policy->getSalvaTerminationDate());

        $policy->incrementSalvaPolicyNumber(new \DateTime('2016-02-01'));
        $this->assertEquals(new \DateTime('2016-02-01'), $policy->getSalvaTerminationDate(1));
        $this->assertNull($policy->getSalvaTerminationDate());
    }

    public function testGetSalvaStartDate()
    {
        $policy = new SalvaPhonePolicy();
        $policy->setStart(new \DateTime('2016-01-01'));
        $this->assertEquals(new \DateTime('2016-01-01'), $policy->getSalvaStartDate());

        $policy->incrementSalvaPolicyNumber(new \DateTime('2016-02-01'));
        $this->assertEquals(new \DateTime('2016-02-01'), $policy->getSalvaStartDate());
        $this->assertEquals(new \DateTime('2016-01-01'), $policy->getSalvaStartDate(1));
    }

    public function testGetSalvaStartDateSaved()
    {
        $policy = new SalvaPhonePolicy();
        $date = new \DateTime('2017-08-08 23:09:00');
        $date->setTimezone(new \DateTimeZone('Europe/London'));
        $policy->setStart($date);
        static::$dm->persist($policy);
        static::$dm->flush();

        $repo = static::$dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $updatedPolicy */
        $updatedPolicy = $repo->find($policy->getId());
        $this->assertEquals(
            new \DateTime('2017-08-09 00:09:00', new \DateTimeZone('Europe/London')),
            $updatedPolicy->getSalvaStartDate()
        );

        $this->assertEquals(
            new \DateTime('2017-08-09 00:00:00', new \DateTimeZone('Europe/London')),
            $this->startOfDay($updatedPolicy->getSalvaStartDate())
        );
    }

    public function testGetPaymentsPerYearCodeAnnual()
    {
        $date = new \DateTime('2016-01-01');
        $policy = static::createUserPolicy(true, $date);
        $policy->setPremiumInstallments(1);
        static::addPayment($policy, 83.88, Salva::YEARLY_TOTAL_COMMISSION);
        $this->assertEquals(1, $policy->getPaymentsPerYearCode());
    }

    public function testGetPaymentsPerYearCodeMonthlyPaid()
    {
        $date = new \DateTime('2016-01-01');
        $policy = static::createUserPolicy(true, $date);
        $policy->setPremiumInstallments(12);
        static::addPayment($policy, 83.88, Salva::YEARLY_TOTAL_COMMISSION);
        $this->assertEquals(1, $policy->getPaymentsPerYearCode());
    }

    public function testGetPaymentsPerYearCodeMonthly()
    {
        $date = new \DateTime('2016-01-01');
        $policy = static::createUserPolicy(true, $date);
        $policy->setPremiumInstallments(12);
        static::addPayment($policy, 6.99, Salva::MONTHLY_TOTAL_COMMISSION);
        $this->assertEquals(12, $policy->getPaymentsPerYearCode());
    }

    public function testGetTotalPremiumPrices()
    {
        $date = new \DateTime('2016-01-01');
        $policy = static::createUserPolicy(true, $date);
        $this->assertEquals(1, $policy->getSalvaProrataMultiplier(null));
        $this->assertEquals($policy->getPremium()->getYearlyPremiumPrice(), $policy->getTotalPremiumPrice(null));
        $this->assertEquals(366, $policy->getDaysInPolicyYear());
        $this->assertEquals(366, $policy->getSalvaDaysInPolicy());
    }

    public function testGetTotalPremiumPrices2017()
    {
        $date = new \DateTime('2017-08-09 00:09:09', new \DateTimeZone('Europe/London'));
        $policy = static::createUserPolicy(true, $date);
        $this->assertEquals(1, $policy->getSalvaProrataMultiplier(null));
        $this->assertEquals($policy->getPremium()->getYearlyPremiumPrice(), $policy->getTotalPremiumPrice(null));
        $this->assertEquals(365, $policy->getDaysInPolicyYear());
        $this->assertEquals(365, $policy->getSalvaDaysInPolicy());
        $this->assertEquals($policy->getStaticEnd(), $policy->getEnd());
    }

    public function testGetTotalPremiumPricesCancelled()
    {
        $date = new \DateTime('2017-08-09 00:09:09', new \DateTimeZone('Europe/London'));
        $policy = static::createUserPolicy(true, $date);
        $policy->setId(rand(1, 9999999));
        $policy->cancel(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2018-08-01'));
        $this->assertEquals(1, $policy->getSalvaProrataMultiplier(null));
        $this->assertEquals($policy->getPremium()->getYearlyPremiumPrice(), $policy->getTotalPremiumPrice(null));
        $this->assertEquals(365, $policy->getDaysInPolicyYear());
        $this->assertEquals(365, $policy->getSalvaDaysInPolicy());
    }

    public function testGetTotalGwpPrices()
    {
        $date = new \DateTime('2016-01-01');
        $policy = static::createUserPolicy(true, $date);
        static::addPayment($policy, 83.88, Salva::YEARLY_TOTAL_COMMISSION);
        $this->assertEquals(1, $policy->getSalvaProrataMultiplier());
        $this->assertEquals(76.6, $policy->getTotalGwp());
        $this->assertEquals(76.6, $policy->getUsedGwp());
    }

    /**
     * getTotalGwp is used for both the ss_tariff estimate in the salva policy creation
     * and the usedFinalPremium in the policy termation
     *
     * this needs to be correct in all cases as it will be what we are billed
     *
     * both: versioned/non-versioned
     * both: claimed/non-claimed
     * both: annual/monthly
     * all: cancellations reasons
     *
     * unpaid monthly policy (annual unpaid is never issued)
     */
    public function testGetTotalGwpAnnualNoClaimNonVersioned()
    {
        // 76.6 * 32 / 366 = 6.70
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_COOLOFF, new \DateTime('2016-01-10'), 0);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-01-10'), 76.6);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-01-10'), 76.6);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-01'), 76.6);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-01'), 6.70);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-01'), 6.70);
    }

    public function testGetTotalGwpMonthlyNoClaimNonVersioned()
    {
        // @codingStandardsIgnoreStart
        // 76.6 / 12 = 6.38
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_COOLOFF, new \DateTime('2016-01-10'), 0, false, false, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-01-10'), 6.38, false, false, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-01-10'), 6.38, false, false, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-01'), 6.38, false, false, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-01'), 6.38, false, false, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-01'), 6.38, false, false, false);
        // @codingStandardsIgnoreEnd
    }

    public function testGetTotalGwpAnnualNoClaimVersioned()
    {
        // @codingStandardsIgnoreStart
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_COOLOFF, new \DateTime('2016-01-10'), -0.42, false, true);
        // 76.6 - 0.42 = 76.18
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-01-10'), 76.18, false, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-01-10'), 76.18, false, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-01'), 76.18, false, true);
        // 76.6 * 32/366 - 0.42 = 6.28
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-01'), 6.28, false, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-01'), 6.28, false, true);
        // @codingStandardsIgnoreEnd
    }

    public function testGetTotalGwpMonthlyNoClaimVersioned()
    {
        // @codingStandardsIgnoreStart
        // 76.6 / 12 = 6.38 - 0.42 = 5.96
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_COOLOFF, new \DateTime('2016-01-10'), -0.42, false, true, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-01-10'), 5.96, false, true, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-01-10'), 5.96, false, true, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-01'), 5.96, false, true, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-01'), 5.96, false, true, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-01'), 5.96, false, true, false);
        // @codingStandardsIgnoreEnd
    }

    public function testGetTotalGwpAnnualClaimNonVersioned()
    {
        // @codingStandardsIgnoreStart
        // cooloff not possible w/claim
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-01-10'), 76.6, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-01-10'), 76.6, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-01'), 76.6, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-01'), 76.6, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-01'), 76.6, true);
        // @codingStandardsIgnoreEnd
    }

    public function testGetTotalGwpMonthlyClaimNonVersioned()
    {
        // @codingStandardsIgnoreStart
        // 76.6 / 12 = 6.38
        // cooloff not possible w/claim
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-01-10'), 6.38, true, false, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-01-10'), 6.38, true, false, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-01'), 6.38, true, false, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-01'), 6.38, true, false, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-01'), 6.38, true, false, false);
        // @codingStandardsIgnoreEnd
    }

    public function testGetTotalGwpAnnualClaimVersioned()
    {
        // @codingStandardsIgnoreStart
        // 76.6 - 0.42 = 76.18
        // cooloff not possible w/claim
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-01-10'), 76.18, true, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-01-10'), 76.18, true, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-01'), 76.18, true, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-01'), 76.18, true, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-01'), 76.18, true, true);
        // @codingStandardsIgnoreEnd
    }

    public function testGetTotalGwpMonthlyClaimVersioned()
    {
        // @codingStandardsIgnoreStart
        // 76.6 / 12 = 6.38 - 0.42 = 5.96
        // cooloff not possible w/claim
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-01-10'), 5.96, true, true, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-01-10'), 5.96, true, true, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-01'), 5.96, true, true, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-01'), 5.96, true, true, false);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-01'), 5.96, true, true, false);
        // @codingStandardsIgnoreEnd
    }

    private function runTestTotalGwp($reason, $date, $gwp, $addClaim = false, $versioned = false, $annual = true)
    {
        // initial policy creation
        $policy = static::createUserPolicy(true, new \DateTime('2016-01-01'));
        $policy->setId(rand(1, 999999));
        $policy->setPhone(self::$phone, new \DateTime('2016-01-01'));
        if ($annual) {
            $policy->setPremiumInstallments(1);
            static::addPayment($policy, 83.88, Salva::YEARLY_TOTAL_COMMISSION);
        } else {
            $policy->setPremiumInstallments(12);
            static::addPayment($policy, 6.99, Salva::MONTHLY_TOTAL_COMMISSION);
        }

        // ss_tariff
        $this->assertEquals(76.6, $policy->getTotalGwp());

        if ($versioned) {
            $partialPolicyAmount = 0.42;
            $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-02'));
            // usedFinalPremium
            $this->assertEquals($partialPolicyAmount, $policy->getUsedGwp($version, true));
            // ss_tariff
            $this->assertEquals(76.6 - $partialPolicyAmount, $policy->getTotalGwp());
        }

        if ($addClaim) {
            $claim = new Claim();
            $claim->setType(Claim::TYPE_LOSS);
            $claim->setStatus(Claim::STATUS_SETTLED);
            $policy->addClaim($claim);
        }

        $policy->cancel($reason, $date);

        // usedFinalPremium
        $this->assertEquals($gwp, $policy->getUsedGwp());
    }

    public function testGetTotalGwpMonthlyNonVersionedUnpaid()
    {
        // initial policy creation
        $policy = static::createUserPolicy(true, new \DateTime('2016-01-01'));
        $policy->setId(rand(1, 999999));
        $policy->setPhone(self::$phone, new \DateTime('2016-01-01'));
        $policy->setPremiumInstallments(12);
        static::addPayment($policy, 6.99, Salva::MONTHLY_TOTAL_COMMISSION);
        // ss_tariff
        $this->assertEquals(76.6, $policy->getTotalGwp());
        $policy->setStatus(SalvaPhonePolicy::STATUS_UNPAID);
        $policy->cancel(SalvaPhonePolicy::CANCELLED_UNPAID, new \DateTime('2016-04-01'));
        // usedFinalPremium (premium is completed taken & policy cancelled)
        $this->assertEquals(6.38, $policy->getUsedGwp());
    }

    public function testHasSalvaPreviousVersionPastMidnight()
    {
        $tz = new \DateTimeZone(Salva::SALVA_TIMEZONE);
        $policy = static::createUserPolicy(true, new \DateTime('2016-01-01', $tz));
        $this->assertFalse($policy->hasSalvaPreviousVersionPastMidnight(null));

        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-01 01:00', $tz));
        $this->assertFalse($policy->hasSalvaPreviousVersionPastMidnight($version));

        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-02 00:01', $tz));
        $this->assertTrue($policy->hasSalvaPreviousVersionPastMidnight($version));

        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-05', $tz));
        $this->assertTrue($policy->hasSalvaPreviousVersionPastMidnight($version));

        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-05 15:00', $tz));
        $this->assertFalse($policy->hasSalvaPreviousVersionPastMidnight($version));

        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-05 23:59', $tz));
        $this->assertFalse($policy->hasSalvaPreviousVersionPastMidnight($version));

        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-06 00:00', $tz));
        $this->assertTrue($policy->hasSalvaPreviousVersionPastMidnight($version));
    }

    public function testGetSalvaDaysInPolicy()
    {
        $tz = new \DateTimeZone(Salva::SALVA_TIMEZONE);
        $policy = static::createUserPolicy(true, new \DateTime('2016-01-01', $tz));
        $this->assertEquals(366, $policy->getSalvaDaysInPolicy(null));

        // First version should always count the day
        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-01 01:00', $tz));
        $this->assertEquals(1, $policy->getSalvaDaysInPolicy($version));

        // First version should always count the day, but only first time
        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-01 02:00', $tz));
        $this->assertEquals(0, $policy->getSalvaDaysInPolicy($version));

        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-02 00:01', $tz));
        $this->assertEquals(1, $policy->getSalvaDaysInPolicy($version));

        // 2nd has already been counted.  3rd, 4th, 5th, so 3
        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-05', $tz));
        $this->assertEquals(3, $policy->getSalvaDaysInPolicy($version));

        // 5th already counted
        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-05 15:00', $tz));
        $this->assertEquals(0, $policy->getSalvaDaysInPolicy($version));

        // 5th already counted
        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-05 23:59', $tz));
        $this->assertEquals(0, $policy->getSalvaDaysInPolicy($version));

        // count 6th
        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-06 00:00', $tz));
        $this->assertEquals(1, $policy->getSalvaDaysInPolicy($version));

        $this->assertEquals(360, $policy->getSalvaDaysInPolicy(null));
    }

    public function testGetSalvaDaysInPolicyAleksFailedTest()
    {
        $tz = new \DateTimeZone(Salva::SALVA_TIMEZONE);
        $policy = static::createUserPolicy(true, new \DateTime('2016-10-01', $tz));
        $this->assertEquals(365, $policy->getSalvaDaysInPolicy(null));
        $this->assertEquals(365, $policy->getDaysInPolicy(new \DateTime('2017-09-30 23:59', $tz)));

        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-10-03', $tz));
        $this->assertEquals(3, $policy->getSalvaDaysInPolicy($version));
        $this->assertEquals(3, $policy->getDaysInPolicy(new \DateTime('2016-10-03', $tz)));
        // 84.26 total premium * 3/365 = 0.69
        $this->assertEquals(0.69, $policy->getTotalPremiumPrice($version));
        // 10.72 * 2/365 = 0.09
        $this->assertEquals(0.09, $policy->getTotalBrokerFee($version));
        $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-10-05 17:00', $tz));
        $this->assertEquals(2, $policy->getSalvaDaysInPolicy($version));
        $this->assertEquals(5, $policy->getDaysInPolicy(new \DateTime('2016-10-05 17:00', $tz)));
        // 84.26 totol premium * 2/365 = 0.46
        $this->assertEquals(0.46, $policy->getTotalPremiumPrice($version));
        // 10.72 * 2/365 = 0.06
        $this->assertEquals(0.06, $policy->getTotalBrokerFee($version));
    }

    public function testBugInRefund()
    {
        $tz = new \DateTimeZone(Salva::SALVA_TIMEZONE);
        // initial policy creation
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testBugInRefund', $this),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy($user, static::$dm, self::$phone6, new \DateTime('2016-09-21 17:12', $tz));
        static::addJudoPayPayment(self::$judopay, $policy, new \DateTime('2016-09-21 17:12', $tz));

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy, new \DateTime('2016-09-21 17:12', $tz));
        self::$dm->flush();

        //\Doctrine\Common\Util\Debug::dump($policy);

        // ss_tariff
        $this->assertEquals(365, $policy->getDaysInPolicyYear());
        $this->assertEquals(365, $policy->getSalvaDaysInPolicy(null));
        $this->assertEquals(1, $policy->getSalvaProrataMultiplier(null));
        // 103.68 / 1.095 = 94.68
        $this->assertEquals(94.68, $policy->getTotalGwp());

        static::$policyService->cancel(
            $policy,
            SalvaPhonePolicy::CANCELLED_USER_REQUESTED,
            false,
            new \DateTime('2016-10-19 12:10', $tz)
        );
        $dispatcher = static::$container->get('event_dispatcher');
        $dispatcher->dispatch(
            PolicyEvent::EVENT_CANCELLED,
            new PolicyEvent($policy, new \DateTime('2016-10-19 12:10', $tz))
        );

        // Invalid values that we originally present
        /*
        $this->assertEquals(8.24, $policy->getTotalPremiumPrice());
        $this->assertEquals(7.95, $policy->getRemainingPremiumPaid([]));
        $this->assertEquals(0.85, $policy->getTotalBrokerFee());
        $this->assertEquals(0.82, $policy->getRemainingTotalCommissionPaid([]));
        */

        // Expected
        // 103.68 * 31 / 365 = 8.81
        $this->assertEquals(8.81, $policy->getTotalPremiumPrice());
        $this->assertEquals(8.81, $policy->getRemainingPremiumPaid([]));

        // 10.72 * 29 / 365 = 0.85
        $this->assertEquals(0.85, $policy->getTotalBrokerFee());
        $this->assertEquals(0.85, $policy->getRemainingTotalCommissionPaid([]));
    }
}
