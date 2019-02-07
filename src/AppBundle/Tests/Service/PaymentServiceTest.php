<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Service\BacsService;
use AppBundle\Service\FraudService;
use AppBundle\Service\PaymentService;
use AppBundle\Service\RouterService;
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
use AppBundle\Document\SCode;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Phone;
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
use AppBundle\Service\SalvaExportService;
use Gedmo\Loggable\Document\LogEntry;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Service\\PaymentServiceTest
 */
class PaymentServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use \AppBundle\Document\DateTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $policyRepo;
    /** @var PaymentService */
    protected static $paymentService;
    /** @var FraudService */
    protected static $fraudService;
    /** @var RouterService */
    protected static $routerService;
    protected static $redis;

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
        /** @var PolicyRepository policyRepo */
        self::$policyRepo = self::$dm->getRepository(Policy::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');
        /** @var PaymentService $paymentService */
        $paymentService = self::$container->get('app.payment');
        self::$paymentService = $paymentService;
        self::$redis = self::$container->get('snc_redis.default');

        /** @var FraudService $fraudService */
        $fraudService = self::$container->get('app.fraud');
        self::$fraudService = $fraudService;

        /** @var RouterService $routerService */
        $routerService = self::$container->get('app.router');
        self::$routerService = $routerService;
    }

    public function tearDown()
    {
    }

    public function testConfirmBacs()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testConfirmBacs', $this),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            null,
            false
        );
        static::$policyService->create($policy, null, true, 12);
        // Exact same start date should be unpaid
        //$this->assertFalse($policy->isPolicyPaidToDate($policy->getStart()));
        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount(new BankAccount());

        $dispatcher = $this->createDispatcher($this->once());
        static::$paymentService->setDispatcher($dispatcher);
        static::$paymentService->confirmBacs($policy, $bacs, $policy->getStart());

        $updatedPolicy = $this->assertPolicyExists(static::$container, $policy);
        $bankAcccount = $updatedPolicy->getPolicyOrUserBacsBankAccount();
        $this->assertNotNull($bankAcccount->getInitialNotificationDate());
        // should be 3 business days + 4 max holidays/weekends (except for xmas - around 14 days)
        $this->assertLessThan(15, $bankAcccount->getInitialNotificationDate()->diff(new \DateTime)->days);
        $this->assertEquals($policy->getBilling(), $bankAcccount->getStandardNotificationDate());
        $this->assertTrue($bankAcccount->isFirstPayment());
    }

    public function testConfirmBacsDuplicate()
    {
        $user1 = static::createUser(
            static::$userManager,
            static::generateEmail('testConfirmBacsDuplicate1', $this),
            'bar',
            null,
            static::$dm
        );

        $policy1 = static::initPolicy(
            $user1,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            null,
            false
        );

        $user2 = static::createUser(
            static::$userManager,
            static::generateEmail('testConfirmBacsDuplicate2', $this),
            'bar',
            null,
            static::$dm
        );

        $policy2 = static::initPolicy(
            $user2,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            null,
            false
        );

        static::$policyService->create($policy1, null, true, 12);
        static::$policyService->create($policy2, null, true, 12);

        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount(new BankAccount());

        $mailer = $this->createMailer($this->exactly(4));
        static::$paymentService->setMailerMailer($mailer);

        static::$paymentService->confirmBacs($policy1, $bacs, $policy1->getStart());
        static::$paymentService->confirmBacs($policy2, $bacs, $policy2->getStart());

        $this->assertGreaterThan(0, self::$fraudService->getDuplicatePolicyBankAccounts($policy1));
        $this->assertGreaterThan(0, self::$fraudService->getDuplicatePolicyBankAccounts($policy2));
    }

    public function testConfirmBacsDifferentPayer()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testConfirmBacsDifferentPayer-user', $this),
            'bar',
            null,
            static::$dm
        );
        $payer = static::createUser(
            static::$userManager,
            static::generateEmail('testConfirmBacsDifferentPayer-payer', $this),
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
        $policy->setPayer($payer);
        static::$policyService->create($policy, new \DateTime('2016-10-01'));

        $this->assertTrue($policy->isDifferentPayer());

        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount(new BankAccount());

        $dispatcher = $this->createDispatcher($this->once());
        static::$paymentService->setDispatcher($dispatcher);
        static::$paymentService->confirmBacs($policy, $bacs);

        $updatedPolicy = $this->assertPolicyExists(static::$container, $policy);
        $bankAcccount = $updatedPolicy->getPolicyOrUserBacsBankAccount();
        $this->assertNotNull($bankAcccount->getInitialNotificationDate());
        $this->assertEquals($policy->getBilling(), $bankAcccount->getStandardNotificationDate());

        $this->assertFalse($updatedPolicy->isDifferentPayer());
    }

    public function testConfirmBacsUnpaid()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testConfirmBacsUnpaid', $this),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            null,
            false
        );
        static::$policyService->create($policy, null, true, 12);
        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();
        // Exact same start date should be unpaid
        //$this->assertFalse($policy->isPolicyPaidToDate($policy->getStart()));
        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount(new BankAccount());

        $dispatcher = $this->createDispatcher($this->once());
        static::$paymentService->setDispatcher($dispatcher);
        static::$paymentService->confirmBacs($policy, $bacs, $policy->getStart());

        $updatedPolicy = $this->assertPolicyExists(static::$container, $policy);
        $bankAcccount = $updatedPolicy->getPolicyOrUserBacsBankAccount();
        $this->assertNotNull($bankAcccount->getInitialNotificationDate());
        // should be 3 business days + 4 max holidays/weekends (except for xmas - around 14 days)
        $this->assertLessThan(15, $bankAcccount->getInitialNotificationDate()->diff(new \DateTime)->days);
        $this->assertEquals($policy->getBilling(), $bankAcccount->getStandardNotificationDate());
        $this->assertTrue($bankAcccount->isFirstPayment());
        $this->assertEquals(Policy::STATUS_ACTIVE, $updatedPolicy->getStatus());
    }

    private function createDispatcher($count)
    {
        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
            ->setMethods(array('dispatch'))
            ->getMock();
        $dispatcher->expects($count)
            ->method('dispatch');

        return $dispatcher;
    }

    private function createMailer($count)
    {
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $mailer->expects($count)
            ->method('send');

        return $mailer;
    }
}
