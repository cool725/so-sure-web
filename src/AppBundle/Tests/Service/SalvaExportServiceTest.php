<?php

namespace AppBundle\Tests\Service;

use AppBundle\Classes\SoSure;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\PolicyRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\Phone;
use AppBundle\Document\CustomerCompany;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Payment\ChargebackPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\SoSurePotRewardPayment;
use AppBundle\Service\SalvaExportService;
use AppBundle\Classes\Salva;

/**
 * @group functional-net
 * @group fixed
 *
 * AppBundle\\Tests\\Service\\SalvaExportServiceTest
 */
class SalvaExportServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var SalvaExportService */
    protected static $salva;
    protected static $xmlFile;
    /** @var PolicyRepository */
    protected static $policyRepo;
    protected static $dispatcher;
    protected static $judopay;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var SalvaExportService $salva */
        $salva = self::$container->get('app.salva');
        self::$salva = $salva;
        self::$dispatcher = self::$container->get('event_dispatcher');
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        /** @var PolicyRepository $policyRepo */
        $policyRepo = self::$dm->getRepository(Policy::class);
        self::$policyRepo = $policyRepo;
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');
        self::$judopay = self::$container->get('app.judopay');
        self::$xmlFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/salva-example-boat.xml",
            self::$container->getParameter('kernel.root_dir')
        );
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);

        // @codingStandardsIgnoreStart
        // testPaymentsCashback is erroring:
        // The "MongoDBODMProxies\__CG__\AppBundle\Document\SalvaPhonePolicy" document with identifier "5ab790f063623960a3731908" could not be found.
        // one of the other tests seems to creating a payment that doesn't have a related policy (or deleting the policy)
        // easiest to just delete all data prior to running
        // @codingStandardsIgnoreEnd
        /** @var PaymentRepository $paymentRepo */
        $paymentRepo = self::$dm->getRepository(Payment::class);
        $payments = $paymentRepo->findAll();
        foreach ($payments as $payment) {
            /** @var Payment $payment */
            self::$dm->remove($payment);
        }
        self::$dm->flush();
    }

    public function setUp()
    {
        static::$salva->setEnvironment('test');
    }

    public function tearDown()
    {
    }

    public function testValidate()
    {
        $xml = file_get_contents(self::$xmlFile);
        $this->assertTrue(self::$salva->validate($xml, SalvaExportService::SCHEMA_POLICY_IMPORT));
    }

    public function testCreateXml()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('create-xml', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(SalvaPhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy);

        $xml = static::$salva->createXml($policy);
        $this->assertTrue(static::$salva->validate($xml, SalvaExportService::SCHEMA_POLICY_IMPORT));
        $this->assertGreaterThan(0, mb_stripos($xml, $user->getId()));

        $tariff = sprintf('<ns2:tariffDate>%s</ns2:tariffDate>', static::$salva->adjustDate($policy->getIssueDate()));
        $this->assertTrue(mb_stripos($xml, $tariff) !== false, sprintf('%s not found in %s', $tariff, $xml));

        $startDate = sprintf(
            '<ns2:insurancePeriodStart>%s</ns2:insurancePeriodStart>',
            static::$salva->adjustDate($policy->getLatestSalvaStartDate())
        );

        $this->assertTrue(mb_stripos($xml, $startDate) !== false);
    }

    public function testCreateXmlRenewal()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCreateXmlRenewal', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(SalvaPhonePolicy::STATUS_PENDING);

        $futureDate = \DateTime::createFromFormat('U', time());
        $futureDate = $futureDate->add(new \DateInterval('P20D'));
        $issueDate = \DateTime::createFromFormat('U', time());
        static::$policyService->create($policy, $futureDate);
        $issueDate2 = clone $issueDate;
        $issueDate2->add(new \DateInterval('PT1S'));

        $xml = static::$salva->createXml($policy);
        $this->assertTrue(static::$salva->validate($xml, SalvaExportService::SCHEMA_POLICY_IMPORT));
        $this->assertGreaterThan(0, mb_stripos($xml, $user->getId()));

        $tariff = sprintf('<ns2:tariffDate>%s</ns2:tariffDate>', static::$salva->adjustDate($issueDate));
        $tariff2 = sprintf('<ns2:tariffDate>%s</ns2:tariffDate>', static::$salva->adjustDate($issueDate2));
        $this->assertTrue(mb_stripos($xml, $tariff) !== false || mb_stripos($xml, $tariff2) !== false);

        $startDate = sprintf(
            '<ns2:insurancePeriodStart>%s</ns2:insurancePeriodStart>',
            static::$salva->adjustDate($futureDate)
        );
        $this->assertContains($startDate, $xml);
    }

    public function testCreateXmlCompany()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testCreateXmlCompany', $this),
            'bar'
        );
        $company = new CustomerCompany();
        $company->setName('foo');
        $company->addUser($user);
        self::$dm->persist($company);
        self::$dm->flush();
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(SalvaPhonePolicy::STATUS_PENDING);
        static::$policyService->create($policy);

        $xml = static::$salva->createXml($policy);
        $this->assertTrue(static::$salva->validate($xml, SalvaExportService::SCHEMA_POLICY_IMPORT));
        $this->assertGreaterThan(0, mb_stripos($xml, $company->getId()));
    }

    public function testNonProdInvalidPolicyQueue()
    {
        $this->assertTrue(static::$salva->queue(new SalvaPhonePolicy(), SalvaExportService::QUEUE_CREATED));
    }

    public function testProdInvalidPolicyQueue()
    {
        static::$salva->setEnvironment('prod');
        $this->assertFalse(static::$salva->queue(new SalvaPhonePolicy(), SalvaExportService::QUEUE_CREATED));
        static::$salva->setEnvironment('test');
    }

    public function testProdValidPolicyQueue()
    {
        $user = static::createUser(
            static::$userManager,
            'notasosureemail@gmail.com',
            'bar'
        );
        static::$salva->setEnvironment('prod');
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        /** @var SalvaPhonePolicy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertTrue($updatedPolicy->isValidPolicy());
        $this->assertTrue(static::$salva->queue($updatedPolicy, SalvaExportService::QUEUE_CREATED));
        // hasn't yet been updated
        $this->assertEquals(SalvaPhonePolicy::SALVA_STATUS_PENDING, $updatedPolicy->getSalvaStatus());
    }

    public function testProdValidPolicyQueueUpdated()
    {
        $user = static::createUser(
            static::$userManager,
            'notasosureemail2@gmail.com',
            'bar'
        );
        static::$salva->setEnvironment('prod');
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        /** @var SalvaPhonePolicy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertTrue($updatedPolicy->isValidPolicy());
        $this->assertTrue(static::$salva->queue($updatedPolicy, SalvaExportService::QUEUE_UPDATED));
        // hasn't yet been updated
        $this->assertEquals(
            SalvaPhonePolicy::SALVA_STATUS_PENDING,
            $updatedPolicy->getSalvaStatus()
        );
    }

    public function testProdInvalidSkippedStatus()
    {
        $user = static::createUser(
            static::$userManager,
            'testProdInvalidSkippedStatus@so-sure.com',
            'bar'
        );
        static::$salva->setEnvironment('prod');
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        /** @var SalvaPhonePolicy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());
        $this->assertTrue($updatedPolicy->isPrefixInvalidPolicy());
        $this->assertEquals(
            SalvaPhonePolicy::SALVA_STATUS_SKIPPED,
            $updatedPolicy->getSalvaStatus()
        );
    }

    /**
     * @expectedException \UnexpectedValueException
     */
    public function testProcessPolicyWait()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('process-policy-wait', $this),
            'bar'
        );
        static::$salva->setEnvironment('prod');
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        // expected a failed judopay exception as we haven't paid, we're simulating a failed judopay refund anyway
        // we no longer throw an exception, so just expect same status
        static::$policyService->cancel($policy, SalvaPhonePolicy::CANCELLED_COOLOFF);

        /** @var SalvaPhonePolicy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());
        // cancellation above should set to wait cancelled
        $this->assertEquals(SalvaPhonePolicy::SALVA_STATUS_WAIT_CANCELLED, $updatedPolicy->getSalvaStatus());
        static::$salva->processPolicy($updatedPolicy, '', null);
    }

    public function testPaymentsCashback()
    {
        $ago = \DateTime::createFromFormat('U', time());
        $ago = $ago->sub(new \DateInterval('P1D'));

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPaymentsCashback', $this),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            \DateTime::createFromFormat('U', time()),
            true
        );
//        static::addJudoPayPayment(self::$judopay, $policy, \DateTime::createFromFormat('U', time()));

        $policy->setStatus(Policy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $ago, true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        // build server delay
        sleep(1);
        
        $now = \DateTime::createFromFormat('U', time());
        $chargeback = new ChargebackPayment();
        $chargeback->setDate($now);
        $chargeback->setSource(Payment::SOURCE_ADMIN);
        $chargeback->setAmount(0 - $policy->getPremiumInstallmentPrice());
        $policy->addPayment($chargeback);
        $chargeback->setPolicy($policy);
        static::$dm->persist($chargeback);
        static::$dm->flush();

        //\Doctrine\Common\Util\Debug::dump($updatedPolicy->getPayments());
        $lines = $this->exportPayments($policy->getPolicyNumber());
        //print_r($lines);
        $this->assertEquals(2, count($lines));
        $this->assertEquals(
            sprintf('"%0.2f"', $policy->getPremiumInstallmentPrice()),
            explode(',', $lines[0])[2],
            json_encode($lines)
        );
        $this->assertEquals(
            sprintf('"%0.2f"', 0 - $policy->getPremiumInstallmentPrice()),
            explode(',', $lines[1])[2],
            json_encode($lines)
        );
    }

    public function testPaymentsPotReward()
    {
        list($policyA, $policyB) = $this->getPendingRenewalPolicies(
            static::generateEmail('testPaymentsPotRewardA', $this),
            static::generateEmail('testPaymentsPotRewardB', $this),
            true,
            new \DateTime('2016-01-01'),
            new \DateTime('2016-01-15'),
            null,
            15,
            15
        );
        $renewalPolicyA = static::$policyService->renew($policyA, 12, null, false, new \DateTime('2016-12-30'));
        $this->assertEquals(Policy::STATUS_RENEWAL, $renewalPolicyA->getStatus());

        static::$policyService->expire($policyA, new \DateTime('2017-01-01'));
        $this->assertEquals(Policy::STATUS_EXPIRED_CLAIMABLE, $policyA->getStatus());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        static::$salva->setDm($dm);

        $lines = $this->exportPayments($policyA->getPolicyNumber(), new \DateTime('2017-01-01'));
        //print_r($lines);
        $this->assertEquals(2, count($lines));
        $dataZero = explode(',', $lines[0]);
        $dataOne = explode(',', $lines[1]);
        $this->assertTrue(in_array('"-10.00"', [$dataZero[2], $dataOne[2]]));
        $this->assertTrue(in_array('"-5.00"', [$dataZero[2], $dataOne[2]]));
        $this->assertNotEquals($dataZero[2], $dataOne[2]);

        /** @var Policy $updatedPolicyA */
        $updatedPolicyA = $dm->getRepository(Policy::class)->find($policyA->getId());
        $this->assertEquals(1, count($updatedPolicyA->getPayments()));
        $foundSoSurePotReward = false;
        foreach ($updatedPolicyA->getAllPayments() as $payment) {
            if ($payment instanceof SoSurePotRewardPayment) {
                $foundSoSurePotReward = true;
            }
        }
        $this->assertTrue($foundSoSurePotReward);
    }

    public function testProcessPaidPolicyWait()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessPaidPolicyWait', $this),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            \DateTime::createFromFormat('U', time()),
            true
        );
        static::addJudoPayPayment(self::$judopay, $policy, \DateTime::createFromFormat('U', time()));

        $policy->setStatus(Policy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, \DateTime::createFromFormat('U', time()), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        // expected a failed judopay exception as we haven't paid, we're simulating a failed judopay refund anyway
        // we no longer throw an exception, so just expect same status
        static::$policyService->cancel($policy, SalvaPhonePolicy::CANCELLED_COOLOFF);

        sleep(1);

        /** @var SalvaPhonePolicy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);

        /** @var Payment $lastPaymentCredit */
        $lastPaymentCredit = $updatedPolicy->getLastSuccessfulUserPaymentCredit();
        $this->assertNotNull($lastPaymentCredit, 'Missing last payment credit');
        $this->assertTrue($this->areEqualToTwoDp(
            $lastPaymentCredit->getAmount(),
            $updatedPolicy->getRefundAmount()
        ), sprintf("%0.2f != %0.2f", $lastPaymentCredit->getAmount(), $updatedPolicy->getRefundAmount()));

        /** @var JudoPayment $refund */
        $refund = $updatedPolicy->getLastPaymentDebit();
        $this->assertNotNull($refund, 'Missing last payment debit');

        // Refunding is very intermittent on the build server. For now, handle both cases. TODO: Fix refund issue
        if ($refund->getResult()) {
            // cancellation above should set to wait cancelled
            $this->assertEquals(SalvaPhonePolicy::SALVA_STATUS_PENDING_CANCELLED, $updatedPolicy->getSalvaStatus());
        } else {
            $this->assertEquals(SalvaPhonePolicy::SALVA_STATUS_WAIT_CANCELLED, $updatedPolicy->getSalvaStatus());
        }
    }

    /**
     * @expectedException \UnexpectedValueException
     */
    public function testPendingCancelled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPendingCancelled', $this),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            \DateTime::createFromFormat('U', time()),
            true
        );
        $policy->setSalvaStatus(SalvaPhonePolicy::SALVA_STATUS_WAIT_CANCELLED);

        static::$salva->processPolicy(
            $policy,
            SalvaExportService::QUEUE_CANCELLED,
            SalvaExportService::CANCELLED_COOLOFF
        );
    }

    public function testBasicExportPolicies()
    {
        $policy = $this->createPolicy('basic-export', new \DateTime('2016-01-01'));

        /** @var Policy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());

        $lines = $this->exportPolicies($updatedPolicy->getPolicyNumber());
        $this->assertEquals(1, count($lines));
        $data = explode('","', trim($lines[0], '"'));

        $this->validatePolicyData($data, $updatedPolicy, 1, Policy::STATUS_ACTIVE, 0);
        $this->validatePolicyPayments($data, $updatedPolicy, 1);
        $this->validateFullYearPolicyAmounts($data, $updatedPolicy);
        $this->validateStaticPolicyData($data, $updatedPolicy);
    }

    public function testExportPaymentMonthEdge()
    {
        $date = new \DateTime('2018-04-01 00:00', SoSure::getSoSureTimezone());
        $policy = $this->createPolicy('testExportPaymentMonthEdge', $date);
        //print $date->format(\DateTime::ATOM) . PHP_EOL;


        $oneMonthBefore = clone $date;
        $oneMonthBefore = $oneMonthBefore->sub(new \DateInterval('P1M'));

        $oneMonthPayment = $this->addPaymentForMonth($policy, 1, $date);
        $twoMonthPayment = $this->addPaymentForMonth($policy, 2, $date);

        static::$dm->flush();

        /** @var Policy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        static::$salva->setDm($dm);

        $lines = $this->exportPayments($updatedPolicy->getPolicyNumber(), $oneMonthBefore);
        // Historical logic - 1/4 appears in March
        $this->assertEquals(1, count($lines));
        //print_r($lines);

        $lines = $this->exportPayments($updatedPolicy->getPolicyNumber(), $date);
        // Historical logic - nothing for april as appears in march
        //$this->assertEquals(0, count($lines));
        //print_r($lines);

        $lines = $this->exportPayments($updatedPolicy->getPolicyNumber(), $oneMonthPayment->getDate());
        // New logic - dates are correct
        $this->assertEquals(1, count($lines));
        //print_r($lines);

        $lines = $this->exportPayments($updatedPolicy->getPolicyNumber(), $twoMonthPayment->getDate());
        // New logic - dates are correct
        $this->assertEquals(1, count($lines));
        //print_r($lines);
    }

    public function testQuoteExportPolicies()
    {
        $policy = $this->createPolicy('testQuoteExportPolicies', new \DateTime('2016-01-01'), true, 'foo"bar');
        static::$dm->flush();

        /** @var Policy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());

        $lines = $this->exportPolicies($updatedPolicy->getPolicyNumber());
        $this->assertEquals(1, count($lines));
        $data = explode('","', trim($lines[0], '"'));

        $this->assertEquals('foo\"bar', $data[7], json_encode($data));
    }

    public function testBasicExportCompanyPolicies()
    {
        $policy = $this->createPolicy('testBasicExportCompanyPolicies', new \DateTime('2016-01-01'), true, 'foo');

        /** @var Policy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());

        $lines = $this->exportPolicies($updatedPolicy->getPolicyNumber());
        $this->assertEquals(1, count($lines));
        $data = explode('","', trim($lines[0], '"'));

        $this->validatePolicyData($data, $updatedPolicy, 1, Policy::STATUS_ACTIVE, 0);
        $this->validatePolicyPayments($data, $updatedPolicy, 1);
        $this->validateFullYearPolicyAmounts($data, $updatedPolicy);
        $this->validateStaticPolicyData($data, $updatedPolicy);
    }

    private function validatePolicyData(
        $data,
        $policy,
        $salvaVersion,
        $status,
        $connections,
        $pot = null
    ) {
        $this->assertEquals(sprintf('%s/%d', $policy->getPolicyNumber(), $salvaVersion), $data[0], json_encode($data));
        $this->assertEquals($status, $data[1], json_encode($data));
        $this->assertEquals(
            static::$salva->adjustDate($policy->getSalvaStartDate($salvaVersion)),
            $data[2],
            json_encode($data)
        );
        $this->assertEquals(static::$salva->adjustDate($policy->getStaticEnd()), $data[3], json_encode($data));

        $this->assertEquals($connections, $data[20], json_encode($data));
        $this->assertEquals($connections * 10, $data[21], json_encode($data));
        if ($pot) {
            $this->assertEquals($pot, $data[22], json_encode($data));
        }
    }

    private function validatePolicyPayments($data, $policy, $numPayments)
    {
        $this->assertEquals($policy->getPremiumInstallmentPrice() * $numPayments, $data[16], json_encode($data));
        $this->assertEquals(Salva::MONTHLY_TOTAL_COMMISSION * $numPayments, $data[19], json_encode($data));
    }

    private function validateFullYearPolicyPayments($data, $policy)
    {
        $this->assertEquals($policy->getPremium()->getYearlyPremiumPrice(), $data[16], json_encode($data));
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $data[19], json_encode($data));
    }

    private function validateProratedPolicyPayments($data, $policy, $days, $daysInYear = 366)
    {
        $this->assertEquals($this->toTwoDp(
            $policy->getPremium()->getYearlyPremiumPrice() * $days / $daysInYear
        ), $data[16], json_encode($data));
        $this->assertEquals($this->toTwoDp(
            Salva::YEARLY_TOTAL_COMMISSION * $days / $daysInYear
        ), $data[19], json_encode($data));
    }

    private function validateFullYearPolicyAmounts($data, $policy)
    {
        $this->assertEquals($policy->getPremium()->getYearlyPremiumPrice(), $data[15], json_encode($data));
        $this->assertEquals($policy->getPremium()->getYearlyIpt(), $data[17], json_encode($data));
        $this->assertEquals(Salva::YEARLY_TOTAL_COMMISSION, $data[18], json_encode($data));
    }

    private function validateFullRefundPolicyAmounts($data)
    {
        $this->assertEquals(0, $data[15], json_encode($data));
        $this->assertEquals(0, $data[17], json_encode($data));
        $this->assertEquals(0, $data[18], json_encode($data));
    }

    private function validateProratedPolicyAmounts($data, $policy, $days, $daysInYear = 366)
    {
        $this->assertEquals($this->toTwoDp(
            $policy->getPremium()->getYearlyPremiumPrice() * $days / $daysInYear
        ), $data[15], json_encode($data));
        $this->assertEquals($this->toTwoDp(
            $policy->getPremium()->getYearlyIpt() * $days / $daysInYear
        ), $data[17], json_encode($data));
        $this->assertEquals($this->toTwoDp(
            Salva::YEARLY_TOTAL_COMMISSION * $days / $daysInYear
        ), $data[18], json_encode($data));
    }

    private function validatePartialYearPolicyAmounts($data, $policy, $numPayments)
    {
        $this->assertEquals(
            $policy->getPremium()->getMonthlyPremiumPrice() * $numPayments,
            $data[15],
            json_encode($data)
        );
        $this->assertEquals($policy->getPremium()->getIpt() * $numPayments, $data[17], json_encode($data));
        $this->assertEquals(Salva::MONTHLY_TOTAL_COMMISSION * $numPayments, $data[18], json_encode($data));
    }

    private function validateRemainingYearPolicyAmounts($data, $policy, $numRemainingPayments)
    {
        $this->assertEquals(
            $policy->getPremium()->getMonthlyPremiumPrice() * $numRemainingPayments,
            $data[15],
            json_encode($data)
        );
        // print $policy->getPremium()->getIpt();
        $this->assertEquals(
            $policy->getPremium()->getYearlyIpt() - $policy->getPremium()->getIpt() * (12 - $numRemainingPayments),
            $data[17],
            json_encode($data)
        );
    }

    private function validateStaticPolicyData($data, $policy)
    {
        // All of this data should be static
        if ($policy->getCompany()) {
            $this->assertEquals($policy->getCompany()->getId(), $data[5], json_encode($data));
            $this->assertEquals('', $data[6], json_encode($data));
            $this->assertEquals($policy->getCompany()->getName(), $data[7], json_encode($data));
        } else {
            $this->assertEquals($policy->getUser()->getId(), $data[5], json_encode($data));
            $this->assertEquals($policy->getUser()->getFirstName(), $data[6], json_encode($data));
            $this->assertEquals($policy->getUser()->getLastName(), $data[7], json_encode($data));
        }
        $this->assertEquals($policy->getPhone()->getMake(), $data[8], json_encode($data));
        $this->assertEquals($policy->getPhone()->getModel(), $data[9], json_encode($data));
        $this->assertEquals($policy->getPhone()->getMemory(), $data[10], json_encode($data));
        $this->assertEquals($policy->getImei(), $data[11], json_encode($data));
        $this->assertEquals($policy->getPhone()->getInitialPrice(), $data[12], json_encode($data));
        $this->assertEquals($policy->getPremiumInstallmentCount(), $data[13], json_encode($data));
        $this->assertEquals($policy->getPremiumInstallmentPrice(), $data[14], json_encode($data));
    }

    private function createPolicy($emailName, $date, $monthly = true, $companyName = null)
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail($emailName, $this),
            'bar'
        );
        if ($companyName) {
            $company = new CustomerCompany();
            $company->setName($companyName);
            $company->addUser($user);
            static::$dm->persist($company);
            static::$dm->flush();
        }

        $policy = static::initPolicy($user, static::$dm, static::$phone, $date, true, false, $monthly);

        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $date);
        static::$policyService->setEnvironment('test');

        // Policy needs to be active to export to salva
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertNotNull($policy->getPremiumInstallmentCount());

        return $policy;
    }

    public function testBasicReissueExportPolicies()
    {
        $policy = $this->createPolicy('basic-resisue', new \DateTime('2016-01-01'));

        // bump the salva policies
        static::$salva->setEnvironment('prod');
        static::$salva->incrementPolicyNumber($policy, new \DateTime('2016-01-31 01:00'));

        /** @var SalvaPhonePolicy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());

        $lines = $this->exportPolicies($updatedPolicy->getPolicyNumber());
        $this->assertEquals(2, count($lines));
        $data1 = explode('","', trim($lines[0], '"'));
        $data2 = explode('","', trim($lines[1], '"'));

        //print $data[0];
        //print_r($data1);
        //print_r($data2);

        $this->validatePolicyData($data1, $updatedPolicy, 1, Policy::STATUS_CANCELLED, 0);
        $this->validatePolicyPayments($data1, $updatedPolicy, 1);
        $this->validateProratedPolicyAmounts($data1, $updatedPolicy, 31);
        //$this->validatePartialYearPolicyAmounts($data1, $updatedPolicy, 1);
        $this->validateStaticPolicyData($data1, $updatedPolicy);

        $this->validatePolicyData($data2, $updatedPolicy, 2, Policy::STATUS_ACTIVE, 0);
        $this->validatePolicyPayments($data2, $updatedPolicy, 0);
        // 366 - 31 = 335
        $this->validateProratedPolicyAmounts($data2, $updatedPolicy, 335);
        //$this->validateRemainingYearPolicyAmounts($data2, $updatedPolicy, 11);
        $this->validateStaticPolicyData($data2, $updatedPolicy);
    }

    public function testVeryShortExportPolicies()
    {
        $policy = $this->createPolicy('testVeryShortExportPolicies', new \DateTime('2017-05-23T18:29:00'));

        // bump the salva policies
        static::$salva->setEnvironment('prod');
        static::$salva->incrementPolicyNumber($policy, new \DateTime('2017-05-24T14:39:00'));

        /** @var SalvaPhonePolicy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());

        $lines = $this->exportPolicies($updatedPolicy->getPolicyNumber());
        $this->assertEquals(2, count($lines));
        $data1 = explode('","', trim($lines[0], '"'));
        $data2 = explode('","', trim($lines[1], '"'));

        //print $data[0];
        //print_r($data1);
        //print_r($data2);

        $this->validatePolicyData($data1, $updatedPolicy, 1, Policy::STATUS_CANCELLED, 0);
        $this->validatePolicyPayments($data1, $updatedPolicy, 1);
        $this->validateProratedPolicyAmounts($data1, $updatedPolicy, 2, 365);
        //$this->validatePartialYearPolicyAmounts($data1, $updatedPolicy, 1);
        $this->validateStaticPolicyData($data1, $updatedPolicy);

        $this->validatePolicyData($data2, $updatedPolicy, 2, Policy::STATUS_ACTIVE, 0);
        $this->validatePolicyPayments($data2, $updatedPolicy, 0);
        // 365 - 2 = 363
        $this->validateProratedPolicyAmounts($data2, $updatedPolicy, 363, 365);
        //$this->validateRemainingYearPolicyAmounts($data2, $updatedPolicy, 11);
        $this->validateStaticPolicyData($data2, $updatedPolicy);
    }

    private function exportPolicies($policyNumber)
    {
        $lines = [];
        foreach (static::$salva->exportPolicies(null) as $line) {
            $data = explode(",", $line);
            $search = sprintf('"%s', $policyNumber);
            if (mb_stripos($data[0], $search) === 0) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function exportPayments($policyNumber, $date = null)
    {
        $lines = [];
        foreach (static::$salva->exportPayments(null, $date) as $line) {
            $data = explode(",", $line);
            $search = sprintf('"%s', $policyNumber);
            if (mb_stripos($data[0], $search) === 0) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function cancelPolicy($policy, $reason, $date, $fullRefund = false)
    {
        static::$policyService->setDispatcher(null);
        static::$policyService->cancel($policy, $reason, false, $date, false, $fullRefund);
        $refundAmount = $policy->getRefundAmount();
        if ($refundAmount > 0) {
            $refundCommissionAmount = $policy->getRefundCommissionAmount();
            $refund = new JudoPayment();
            $refund->setAmount(0 - $refundAmount);
            $refund->setCommission(
                $policy->getProrataCoverholderCommissionRefund(),
                $policy->getBrokerCoverholderCommissionRefund()
            );
            $refund->setReceipt(sprintf('R-%s', rand(1, 999999)));
            $refund->setResult(JudoPayment::RESULT_SUCCESS);
            $refund->setDate($date);
            $policy->addPayment($refund);
            static::$dm->persist($refund);
            static::$dm->flush();
        }
        static::$policyService->setDispatcher(static::$dispatcher);
    }

    public function testBasicCooloffExportMonthlyPolicies()
    {
        $policy = $this->createPolicy('basic-export-cooloff', new \DateTime('2016-01-01'));

        $this->cancelPolicy($policy, Policy::CANCELLED_COOLOFF, new \DateTime('2016-01-02'));

        /** @var Policy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());

        $lines = $this->exportPolicies($updatedPolicy->getPolicyNumber());
        $this->assertEquals(1, count($lines));
        $data = explode('","', trim($lines[0], '"'));

        // full refund, so number of payments should be 0
        $this->validatePolicyData($data, $updatedPolicy, 1, Policy::STATUS_CANCELLED, 0);
        $this->validatePolicyPayments($data, $updatedPolicy, 0);
        $this->validateFullRefundPolicyAmounts($data);
        $this->validateStaticPolicyData($data, $updatedPolicy);
    }

    public function testBasicCooloffExportMonthlyPoliciesXml()
    {
        $date = new \DateTime('2016-01-01');
        $policy = $this->createPolicy('basic-export-cooloff-xml', $date);

        $this->cancelPolicy($policy, Policy::CANCELLED_COOLOFF, new \DateTime('2016-01-02'));
        $xml = self::$salva->cancelXml($policy, Policy::CANCELLED_COOLOFF, new \DateTime('2016-01-02'))['xml'];
        $this->assertContains('<n1:usedFinalPremium n2:currency="GBP">0.00</n1:usedFinalPremium>', $xml);
    }

    public function testCancelNoRefundXml()
    {
        $date = new \DateTime('2016-01-01');
        $policy = $this->createPolicy('testCancelNoRefundXml', $date);
        static::$dm->flush();

        $this->addPaymentForMonth($policy, 0, $date);
        $this->addPaymentForMonth($policy, 1, $date);
        $this->addPaymentForMonth($policy, 2, $date);
        $this->addPaymentForMonth($policy, 3, $date);
        $this->addPaymentForMonth($policy, 4, $date);

        $this->cancelPolicy($policy, Policy::CANCELLED_USER_REQUESTED, new \DateTime('2016-04-30'));
        $xml = self::$salva->cancelXml($policy, Policy::CANCELLED_USER_REQUESTED, new \DateTime('2016-04-30'))['xml'];
        $this->assertContains('<n1:usedFinalPremium n2:currency="GBP">25.54</n1:usedFinalPremium>', $xml);
        $this->assertContains('<n1:terminationTime>2016-04-30T01:00:00</n1:terminationTime>', $xml);
    }

    public function testCancelFullRefundXml()
    {
        $date = new \DateTime('2016-01-01');
        $policy = $this->createPolicy('testCancelFullRefundXml', $date);
        static::$dm->flush();

        $this->addPaymentForMonth($policy, 0, $date);
        $this->addPaymentForMonth($policy, 1, $date);
        $this->addPaymentForMonth($policy, 2, $date);
        $this->addPaymentForMonth($policy, 3, $date);
        $this->addPaymentForMonth($policy, 4, $date);

        $this->cancelPolicy($policy, Policy::CANCELLED_USER_REQUESTED, new \DateTime('2016-05-01'), true);
        $xml = self::$salva->cancelXml($policy, Policy::CANCELLED_USER_REQUESTED, new \DateTime('2016-05-01'))['xml'];
        $this->assertContains('<n1:usedFinalPremium n2:currency="GBP">0</n1:usedFinalPremium>', $xml);
        $this->assertContains('<n1:terminationTime>2016-01-01T02:59:00</n1:terminationTime>', $xml);
    }

    public function testBasicCooloffExportYearlyPolicies()
    {
        $policy = $this->createPolicy('basic-export-yearly-cooloff', new \DateTime('2016-01-01'), false);

        $this->cancelPolicy($policy, Policy::CANCELLED_COOLOFF, new \DateTime('2016-01-02'));

        /** @var Policy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());

        $lines = $this->exportPolicies($updatedPolicy->getPolicyNumber());
        $this->assertEquals(1, count($lines));
        $data = explode('","', trim($lines[0], '"'));

        //print $data[0];
        //print_r($data1);
        //print_r($data2);

        $this->validatePolicyData($data, $updatedPolicy, 1, Policy::STATUS_CANCELLED, 0);
        $this->validatePolicyPayments($data, $updatedPolicy, 0);
        $this->validateFullRefundPolicyAmounts($data);
        $this->validateStaticPolicyData($data, $updatedPolicy);
    }

    public function testBasicCooloffExportYearlyPoliciesXml()
    {
        $policy = $this->createPolicy('basic-export-yearly-cooloff-xml', new \DateTime('2016-01-01'), false);

        $this->cancelPolicy($policy, Policy::CANCELLED_COOLOFF, new \DateTime('2016-01-02'));
        $xml = self::$salva->cancelXml($policy, Policy::CANCELLED_COOLOFF, new \DateTime('2016-01-02'))['xml'];
        $this->assertContains('<n1:usedFinalPremium n2:currency="GBP">0.00</n1:usedFinalPremium>', $xml);
    }

    public function testCooloffConnecedHasNoConnectionsPolicies()
    {
        $policyCooloff = $this->createPolicy('connected-export-cooloff', new \DateTime('2016-01-01'));
        $policy = $this->createPolicy('connected-export', new \DateTime('2016-01-01'));
        $invitation = self::$container->get('app.invitation');
        $invitation->setEnvironment('prod');
        $invitation->setDebug(true);
        static::connectPolicies(
            $invitation,
            $policy,
            $policyCooloff,
            new \DateTime('2016-01-02')
        );
        $invitation->setEnvironment('test');
        $this->assertGreaterThan(0, count($policyCooloff->getConnections()));
        $this->assertGreaterThan(0, $policyCooloff->getPotValue());

        $this->cancelPolicy($policyCooloff, Policy::CANCELLED_COOLOFF, new \DateTime('2016-01-02'));

        /** @var Policy $updatedPolicyCooloff */
        $updatedPolicyCooloff = static::$policyRepo->find($policyCooloff->getId());

        $lines = $this->exportPolicies($updatedPolicyCooloff->getPolicyNumber());
        $this->assertEquals(1, count($lines));
        $data = explode('","', trim($lines[0], '"'));

        $this->validatePolicyData($data, $updatedPolicyCooloff, 1, Policy::STATUS_CANCELLED, 0, 0);
        $this->validatePolicyPayments($data, $updatedPolicyCooloff, 0);
        $this->validateFullRefundPolicyAmounts($data);
        $this->validateStaticPolicyData($data, $updatedPolicyCooloff);
    }

    public function testBasicWreckageExportYearlyPolicies()
    {
        $policy = $this->createPolicy('basic-export-yearly-wreckage', new \DateTime('2016-01-01'), false);
        // 31 + 29 + 31 + 30 + 31 + 1
        $this->cancelPolicy($policy, Policy::CANCELLED_WRECKAGE, new \DateTime('2016-06-01'));

        /** @var Policy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());

        $lines = $this->exportPolicies($updatedPolicy->getPolicyNumber());
        $this->assertEquals(1, count($lines));
        $data = explode('","', trim($lines[0], '"'));

        $this->validatePolicyData($data, $updatedPolicy, 1, Policy::STATUS_CANCELLED, 0);
        $this->validateProratedPolicyPayments($data, $updatedPolicy, 153);
        $this->validateProratedPolicyAmounts($data, $updatedPolicy, 153);
        $this->validateStaticPolicyData($data, $updatedPolicy);
    }

    public function testExportAleksFailedTest()
    {
        $policy = $this->createPolicy('export-failed-test', new \DateTime('2016-10-01'), false);

        $version = static::$salva->incrementPolicyNumber($policy, new \DateTime('2016-10-03'));

        $this->cancelPolicy($policy, Policy::CANCELLED_WRECKAGE, new \DateTime('2016-10-05 17:00'));

        /** @var SalvaPhonePolicy $updatedPolicy */
        $updatedPolicy = static::$policyRepo->find($policy->getId());

        $lines = $this->exportPolicies($updatedPolicy->getPolicyNumber());
        $this->assertEquals(2, count($lines));
        $data1 = explode('","', trim($lines[0], '"'));
        $data2 = explode('","', trim($lines[1], '"'));

        $this->assertEquals(3, $updatedPolicy->getSalvaDaysInPolicy(1));
        $this->assertEquals(2, $updatedPolicy->getSalvaDaysInPolicy(null));

        $this->validateStaticPolicyData($data1, $updatedPolicy);
        $this->validateStaticPolicyData($data2, $updatedPolicy);

        $this->validatePolicyData($data1, $updatedPolicy, 1, Policy::STATUS_CANCELLED, 0);
        $this->validatePolicyData($data2, $updatedPolicy, 2, Policy::STATUS_CANCELLED, 0);

        // should be the full year's payment
        $this->validateFullYearPolicyPayments($data1, $updatedPolicy);
        $this->validateProratedPolicyAmounts($data1, $updatedPolicy, 3, 365);

        // and a big refund
        $this->validateProratedPolicyPayments($data2, $updatedPolicy, -360, 365);
        $this->validateProratedPolicyAmounts($data2, $updatedPolicy, 2, 365);
    }

    public function testBasicWreckageExportYearlyPoliciesXml()
    {
        $policy = $this->createPolicy('basic-export-yearly-wreckage-xml', new \DateTime('2016-01-01'), false);

        $this->cancelPolicy($policy, Policy::CANCELLED_WRECKAGE, new \DateTime('2016-06-01'));
        $xml = self::$salva->cancelXml($policy, Policy::CANCELLED_WRECKAGE, new \DateTime('2016-06-01'))['xml'];
        // 6.38 gwp / 76.60 yearly gpw  * 153 / 366 = 32.02
        $this->assertContains('<n1:usedFinalPremium n2:currency="GBP">32.02</n1:usedFinalPremium>', $xml);
    }

    public function testVersionedWreckageExportYearlyPoliciesXml()
    {
        $policy = $this->createPolicy('versioned-export-yearly-wreckage-xml', new \DateTime('2016-01-01'), false);

        static::$salva->incrementPolicyNumber($policy, new \DateTime('2016-01-31 01:00'));

        $this->cancelPolicy($policy, Policy::CANCELLED_WRECKAGE, new \DateTime('2016-06-01'));
        $xml = self::$salva->cancelXml($policy, Policy::CANCELLED_WRECKAGE, new \DateTime('2016-06-01'))['xml'];
        // 6.38 gwp / 76.60 yearly gpw  * (153 - 31) / 366 = 25.54
        $this->assertContains('<n1:usedFinalPremium n2:currency="GBP">25.54</n1:usedFinalPremium>', $xml);
    }

    public function testVersionedMonthlyFirstDueDatePoliciesXml()
    {
        $policy = $this->createPolicy('testVersionedMonthlyFirstDueDatePoliciesXml', new \DateTime('2016-01-01'));
        $xml = static::$salva->createXml($policy);
        $this->assertContains('<ns2:firstDueDate>2016-01-01</ns2:firstDueDate>', $xml);

        static::$salva->incrementPolicyNumber($policy, new \DateTime('2016-01-10 01:00'));

        $xml = static::$salva->createXml($policy);
        $this->assertContains('<ns2:firstDueDate>2016-02-01</ns2:firstDueDate>', $xml);

        static::$salva->incrementPolicyNumber($policy, new \DateTime('2016-02-15 01:00'));

        $xml = static::$salva->createXml($policy);
        $this->assertContains('<ns2:firstDueDate>2016-02-01</ns2:firstDueDate>', $xml);
    }

    public function testVersionedYearlyFirstDueDatePoliciesXml()
    {
        $policy = $this->createPolicy(
            'testVersionedYearlyFirstDueDatePoliciesXml',
            new \DateTime('2016-01-01'),
            false
        );
        $xml = static::$salva->createXml($policy);
        $this->assertNotContains('<ns2:firstDueDate>', $xml);

        static::$salva->incrementPolicyNumber($policy, new \DateTime('2016-01-10 01:00'));

        $xml = static::$salva->createXml($policy);
        $this->assertNotContains('<ns2:firstDueDate>', $xml);

        static::$salva->incrementPolicyNumber($policy, new \DateTime('2016-02-15 01:00'));

        $xml = static::$salva->createXml($policy);
        $this->assertNotContains('<ns2:firstDueDate>', $xml);
    }

    private function addPaymentForMonth(Policy $policy, $month, \DateTime $date)
    {
        $future = clone $date;
        $future = $future->add(new \DateInterval(sprintf('P%dM', $month)));
            //print $oneMonth->format(\DateTime::ATOM) . PHP_EOL;
        $payment = self::addPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            sprintf("%s%d", $policy->getId(), $month),
            $future
        );

        return $payment;
    }
}
