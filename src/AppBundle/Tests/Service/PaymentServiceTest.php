<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Service\BacsService;
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
    protected static $paymentService;
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
        self::$paymentService = self::$container->get('app.payment');
        self::$redis = self::$container->get('snc_redis.default');
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
            new \DateTime('2016-10-01'),
            true
        );
        static::$policyService->create($policy, new \DateTime('2016-10-01'));

        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount(new BankAccount());

        $dispatcher = $this->createDispatcher($this->once());
        static::$paymentService->setDispatcher($dispatcher);
        static::$paymentService->confirmBacs($policy, $bacs);

        $updatedPolicy = $this->assertPolicyExists(static::$container, $policy);
        $bankAcccount = $updatedPolicy->getUser()->getPaymentMethod()->getBankAccount();
        $this->assertNotNull($bankAcccount->getInitialNotificationDate());
        $this->assertEquals($policy->getBilling(), $bankAcccount->getStandardNotificationDate());
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
        $bankAcccount = $updatedPolicy->getUser()->getPaymentMethod()->getBankAccount();
        $this->assertNotNull($bankAcccount->getInitialNotificationDate());
        $this->assertEquals($policy->getBilling(), $bankAcccount->getStandardNotificationDate());

        $this->assertFalse($updatedPolicy->isDifferentPayer());
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
}
