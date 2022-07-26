<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Policy;
use AppBundle\Event\BacsEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Listener\BacsListener;
use AppBundle\Service\BacsService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use AppBundle\Listener\UserListener;
use AppBundle\Listener\DoctrineUserListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\UserEvent;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-net
 */
class BacsListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $userRepo;
    protected static $redis;
    /** @var BacsService */
    protected static $bacsService;

    /** @var BacsListener */
    protected static $bacsListener;

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
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        /** @var BacsListener $bacsListener */
        $bacsListener = self::$container->get('app.listener.bacs');
        self::$bacsListener = $bacsListener;
        self::$redis = self::$container->get('snc_redis.default');
        /** @var BacsService $bacsService */
        $bacsService = self::$container->get('app.bacs');
        self::$bacsService = $bacsService;
    }

    public function tearDown()
    {
    }

    public function testUserChangeNamePolicy()
    {
        self::$redis->flushdb();
        $this->assertEquals(0, self::$redis->hlen(BacsService::KEY_BACS_CANCEL));

        $user = static::createUser(
            self::$userManager,
            self::generateEmail('testUserChangeNamePolicy', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');

        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm)
        );
        $policy->setStatus(Policy::STATUS_UNPAID);

        $bankAccount = new BankAccount();
        $bankAccount->setAccountName('f bar');
        $bankAccount->setAccountNumber('12345678');
        $bankAccount->setSortCode('000099');
        $bankAccount->setReference(random_int(111111, 999999));

        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount($bankAccount);
        $policy->setPaymentMethod($bacs);
        static::$dm->persist($user);
        static::$dm->persist($policy);
        static::$dm->flush();

        $user->setLastName('rab');
        static::$dm->flush();

        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);
        $this->assertEquals(
            BankAccount::MANDATE_CANCELLED,
            $updatedPolicy->getPaymentMethod()->getBankAccount()->getMandateStatus()
        );
    }

    public function testUserChangeNameSameAccountPolicy()
    {
        $user = static::createUser(
            self::$userManager,
            self::generateEmail('testUserChangeNameSameAccountPolicy', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');

        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm)
        );
        $policy->setStatus(Policy::STATUS_UNPAID);

        $bankAccount = new BankAccount();
        $bankAccount->setAccountName('f bar');
        $bankAccount->setMandateStatus(BankAccount::MANDATE_SUCCESS);
        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount($bankAccount);
        $policy->setPaymentMethod($bacs);
        static::$dm->persist($user);
        static::$dm->persist($policy);
        static::$dm->flush();

        $user->setFirstName('f');
        static::$dm->flush();

        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);
        $this->assertEquals(
            BankAccount::MANDATE_SUCCESS,
            $updatedPolicy->getPaymentMethod()->getBankAccount()->getMandateStatus()
        );
    }

    public function testBankAccountChangedEventPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testBankAccountChangedEventPolicy', $this),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm)
        );
        static::$dm->persist($user);
        static::$dm->persist($policy);
        static::$dm->flush();

        self::$redis->flushdb();
        $this->assertEquals(0, self::$redis->hlen(BacsService::KEY_BACS_CANCEL));
        $bankAccount = new BankAccount();
        $bankAccount->setAccountName('f bar');
        $bankAccount->setAccountNumber('12345678');
        $bankAccount->setSortCode('000099');
        $bankAccount->setReference('123');
        $bacsEvent = new BacsEvent($bankAccount);
        $bacsEvent->setPolicy($policy);

        self::$bacsListener->onBankAccountChangedEvent($bacsEvent);

        $this->assertEquals(1, self::$redis->hlen(BacsService::KEY_BACS_CANCEL));
        $cancellations = self::$bacsService->getBacsCancellations();
        $data = $cancellations[0];
        $this->assertEquals($bankAccount->getSortCode(), $data['sortCode']);
        $this->assertEquals($bankAccount->getAccountName(), $data['accountName']);
        $this->assertEquals($bankAccount->getAccountNumber(), $data['accountNumber']);
        $this->assertEquals($bankAccount->getReference(), $data['reference']);
        $this->assertEquals($user->getId(), $data['id']);
    }

    public function testPolicyBacsCreated()
    {
        self::$redis->flushdb();
        $this->assertEquals(0, self::$redis->llen(BacsService::KEY_BACS_QUEUE));

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyBacsCreated', $this),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm)
        );

        self::$bacsListener->onPolicyBacsCreated(new PolicyEvent($policy));
        $this->assertEquals(1, self::$redis->llen(BacsService::KEY_BACS_QUEUE));
    }

    public function testPolicyUpdatedBilling()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyUpdatedBilling', $this),
            'bar',
            null,
            static::$dm
        );
        $bacs = new BacsPaymentMethod();
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm)
        );

        self::$bacsListener->onPolicyUpdatedBilling(new PolicyEvent($policy));
        // TODO: check logger
        $this->assertTrue(true);
    }

    public function testPolicyUpdatedBillingPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyUpdatedBillingPolicy', $this),
            'bar',
            null,
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm)
        );
        $bacs = new BacsPaymentMethod();
        $policy->setPaymentMethod($bacs);

        self::$bacsListener->onPolicyUpdatedBilling(new PolicyEvent($policy));
        // TODO: check logger
        $this->assertTrue(true);
    }
}
