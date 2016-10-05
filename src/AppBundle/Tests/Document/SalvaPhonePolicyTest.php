<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PolicyTerms;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Tests\UserClassTrait;
use AppBundle\Classes\Salva;

/**
 * @group functional-nonet
 */
class SalvaPhonePolicyTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use UserClassTrait;

    protected static $container;
    protected static $dm;
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
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
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

    public function testGetTotalPremiumPrices()
    {
        $date = new \DateTime('2016-01-01');
        $policy = static::createUserPolicy(true, $date);
        $this->assertEquals(1, $policy->getSalvaProrataMultiplier());
        $this->assertEquals(83.88, $policy->getTotalPremiumPrice());
    }

    public function testGetTotalGwpPrices()
    {
        $date = new \DateTime('2016-01-01');
        $policy = static::createUserPolicy(true, $date);
        static::addPayment($policy, 83.88, Salva::YEARLY_TOTAL_COMMISSION);
        $this->assertEquals(1, $policy->getSalvaProrataMultiplier());
        $this->assertEquals(76.60, $policy->getTotalGwp());
        $this->assertEquals(76.60, $policy->getUsedGwp());
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
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_COOLOFF, new \DateTime('2016-01-10'), 0);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-01-10'), 76.60);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-01-10'), 76.60);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-01'), 6.70);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-01'), 6.70);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-01'), 6.70);
    }

    public function testGetTotalGwpMonthlyNoClaimNonVersioned()
    {
        // @codingStandardsIgnoreStart
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
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-01-10'), 76.18, false, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-01-10'), 76.18, false, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-01'), 6.49, false, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-01'), 6.49, false, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-01'), 6.49, false, true);
        // @codingStandardsIgnoreEnd
    }

    public function testGetTotalGwpMonthlyNoClaimVersioned()
    {
        // @codingStandardsIgnoreStart
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
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD, new \DateTime('2016-01-10'), 76.60, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_SUSPECTED_FRAUD, new \DateTime('2016-01-10'), 76.60, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_USER_REQUESTED, new \DateTime('2016-02-01'), 76.60, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_WRECKAGE, new \DateTime('2016-02-01'), 76.60, true);
        $this->runTestTotalGwp(SalvaPhonePolicy::CANCELLED_DISPOSSESSION, new \DateTime('2016-02-01'), 76.60, true);
        // @codingStandardsIgnoreEnd
    }

    public function testGetTotalGwpMonthlyClaimNonVersioned()
    {
        // @codingStandardsIgnoreStart
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
        $this->assertEquals(76.60, $policy->getTotalGwp());

        if ($versioned) {
            $partialPolicyAmount = 0.42;
            $version = $policy->incrementSalvaPolicyNumber(new \DateTime('2016-01-02'));
            // usedFinalPremium
            $this->assertEquals($partialPolicyAmount, $policy->getUsedGwp($version, true));
            // ss_tariff
            $this->assertEquals(76.60 - $partialPolicyAmount, $policy->getTotalGwp());
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
        //$this->assertTrue(false, 'not yet implemented');
        // initial policy creation
        $policy = static::createUserPolicy(true, new \DateTime('2016-01-01'));
        $policy->setId(rand(1, 999999));
        $policy->setPhone(self::$phone, new \DateTime('2016-01-01'));
        $policy->setPremiumInstallments(12);
        static::addPayment($policy, 6.99, Salva::MONTHLY_TOTAL_COMMISSION);

        // ss_tariff
        $this->assertEquals(76.60, $policy->getTotalGwp());

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
}
