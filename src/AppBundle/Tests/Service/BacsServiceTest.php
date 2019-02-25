<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Service\BacsService;
use AppBundle\Service\PaymentService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 * AppBundle\\Tests\\Service\\BacsServiceTest
 */
class BacsServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use DateTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $xmlFile;
    /** @var BacsService */
    protected static $bacsService;
    protected static $policyService;

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
        self::$xmlFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/bacs/ADDACS.xml",
            self::$container->getParameter('kernel.root_dir')
        );
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        /** @var BacsService $bacsService */
        $bacsService = self::$container->get('app.bacs');
        self::$bacsService = $bacsService;
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');

        /** @var PaymentService $paymentService */
        $paymentService = self::$container->get('app.payment');
        self::$paymentService = $paymentService;
    }

    public function tearDown()
    {
        self::$dm->clear();
    }

    public function testBacsXmlPolicyAndPreviousBankAccounts()
    {
        $now = $this->now();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testBacsXmlPolicy', $this),
            'bar'
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $now,
            true
        );
        static::$policyService->setDispatcher(null);
        static::$policyService->create($policy, $now);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $this->setValidBacsPaymentMethodForPolicy($policy, 'SOSURE01');
        static::$dm->flush();
        $this->setValidBacsPaymentMethodForPolicy($policy, 'SOSURE02');
        static::$dm->flush();
        $this->assertNotNull($policy->getBacsPaymentMethod());
        if ($policy->getBacsPaymentMethod()) {
            $this->assertCount(2, $policy->getBacsPaymentMethod()->getPreviousBankAccounts());
        }
        $this->assertNotNull($policy->getBacsBankAccount());
        if ($policy->getBacsBankAccount()) {
            $this->assertEquals('SOSURE02', $policy->getBacsBankAccount()->getReference());
        }

        $results = self::$bacsService->addacs(self::$xmlFile);
        $this->assertTrue($results['success']);

        $updatePolicy = $this->assertPolicyExists(self::$container, $policy);
        $this->assertEquals(
            BankAccount::MANDATE_CANCELLED,
            $updatePolicy->getPaymentMethod()->getBankAccount()->getMandateStatus()
        );
    }

    private function setValidBacsPaymentMethodForPolicy(Policy $policy, $reference = null, \DateTime $date = null)
    {
        $bacs = null;
        $name = $policy->getUser()->getName();
        if ($policy->getBacsPaymentMethod()) {
            $policy->getBacsPaymentMethod()->setBankAccount($this->getBankAcccount($name, $reference, $date));
        } else {
            $bacs = $this->getBacsPaymentMethod($name, $reference, $date);
            $policy->setPaymentMethod($bacs);
        }

        return $bacs;
    }

    private function getBacsPaymentMethod($name, $reference = null, \DateTime $date = null)
    {
        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount($this->getBankAcccount($name, $reference, $date));

        return $bacs;
    }

    private function getBankAcccount($name, $reference = null, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $bankAccount = new BankAccount();
        $bankAccount->setMandateStatus(BankAccount::MANDATE_SUCCESS);
        if (!$reference) {
            $reference = sprintf('SOSURE%d', rand(1, 999999));
        }
        $bankAccount->setReference($reference);
        $bankAccount->setSortCode('000099');
        $bankAccount->setAccountNumber('87654321');
        $bankAccount->setAccountName($name);
        $bankAccount->setInitialPaymentSubmissionDate($date);

        return $bankAccount;
    }

    public function testBacsPayment()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testBacsPayment', $this));
        $this->setValidBacsPaymentMethodForPolicy($policy);
        $payment = static::$bacsService->bacsPayment($policy, 'test', 1.01);
        $this->assertEquals(1.01, $payment->getAmount());
        $this->assertEquals('test', $payment->getNotes());
        $this->assertNull($payment->isSuccess());
    }

    public function testCheckSubmissionFile()
    {
        $passingFile = [self::$bacsService->getHeader()];
        $failingFile = [implode(',', [
            '"Processing Date"',
            '"Action"',
            '"BACS Transaction Code"',
            '"Name"',
            '"Sort Code"',
            '"Account"',
            '"UserId"',
            '"PolicyId"',
            '"PaymentId"',
        ])];
        $this->assertTrue(self::$bacsService->checkSubmissionFile($passingFile));
        $this->assertFalse(self::$bacsService->checkSubmissionFile($failingFile));


    }

    public function testExportPaymentsDebits()
    {
        $now = \DateTime::createFromFormat('U', time());
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testExportPaymentsDebits', $this),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $now,
            true
        );
        static::$policyService->setDispatcher(null);
        static::$policyService->create($policy, $now);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $bacs = $this->setValidBacsPaymentMethodForPolicy($policy, rand(111111, 999999), $now);
        //$bacs = $this->setValidBacsPaymentMethod($policy->getUser(), null, $now);
        $bacs->getBankAccount()->setInitialPaymentSubmissionDate($now);
        self::$paymentService->confirmBacs($policy, $bacs);

        static::$dm->flush();

        $oneMonth = clone $now;
        $oneMonth = $oneMonth->add(new \DateInterval('P1M'));
        $twoMonth = clone $now;
        $twoMonth = $twoMonth->add(new \DateInterval('P2M'));
        $threeMonth = clone $now;
        $threeMonth = $threeMonth->add(new \DateInterval('P3M'));

        $metaData = [];

        $debits = self::$bacsService->exportPaymentsDebits('TEST', $oneMonth, '1', $metaData);
        $this->assertEquals(1, count($debits));
        static::$dm->flush();

        // re-running should fail as changed to pending
        $debits = self::$bacsService->exportPaymentsDebits('TEST', $oneMonth, '1', $metaData);
        $this->assertEquals(0, count($debits));
        static::$dm->flush();

        // Cancelled mandate should prevent payments
        $bacs->getBankAccount()->setMandateStatus(BankAccount::MANDATE_CANCELLED);
        static::$dm->flush();
        $debits = self::$bacsService->exportPaymentsDebits('TEST', $twoMonth, '1', $metaData);
        $this->assertEquals(0, count($debits));
        static::$dm->flush();

        // Cancelled policy should prevent payments
        $bacs->getBankAccount()->setMandateStatus(BankAccount::MANDATE_SUCCESS);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        static::$dm->flush();
        $debits = self::$bacsService->exportPaymentsDebits('TEST', $threeMonth, '1', $metaData);
        $this->assertEquals(0, count($debits));
        static::$dm->flush();
    }

    public function testExportPaymentsDebitsPreventExpirationAfter()
    {
        $now = \DateTime::createFromFormat('U', time());
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testExportPaymentsDebitsPreventExpirationAfter', $this),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $now,
            true
        );
        static::$policyService->setDispatcher(null);
        static::$policyService->create($policy, $now);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $bacs = $this->setValidBacsPaymentMethodForPolicy($policy, rand(111111, 999999), $now);
        $bacs->getBankAccount()->setInitialPaymentSubmissionDate($now);
        self::$paymentService->confirmBacs($policy, $bacs);

        static::$dm->flush();

        $expire = clone $policy->getPolicyExpirationDate();
        $afterExpire = clone $expire;
        $afterExpire = $this->subBusinessDays($afterExpire, 4);
        $metaData = [];

        $scheduledPayment = $policy->getNextScheduledPayment();

        $scheduledPayment->setScheduled($afterExpire);
        static::$dm->flush();
        $debits = self::$bacsService->exportPaymentsDebits('TEST', $afterExpire, '1', $metaData);
        $this->assertEquals(0, count($debits));
    }
}
