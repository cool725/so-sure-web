<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Event\BacsEvent;
use AppBundle\Listener\BacsListener;
use AppBundle\Service\BacsService;
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
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$bacsListener = self::$container->get('app.listener.bacs');
        self::$redis = self::$container->get('snc_redis.default');
        self::$bacsService = self::$container->get('app.bacs');
    }

    public function tearDown()
    {
    }

    public function testUserChangeName()
    {
        $user = static::createUser(
            self::$userManager,
            self::generateEmail('testUserChangeName', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $bankAccount = new BankAccount();
        $bankAccount->setAccountName('f bar');
        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount($bankAccount);
        $user->setPaymentMethod($bacs);
        static::$dm->flush();

        $user->setLastName('rab');
        static::$dm->flush();

        $updatedUser = $this->assertUserExists(self::$container, $user);
        $this->assertEquals(
            BankAccount::MANDATE_CANCELLED,
            $updatedUser->getPaymentMethod()->getBankAccount()->getMandateStatus()
        );
    }

    public function testUserChangeNameSameAccount()
    {
        $user = static::createUser(
            self::$userManager,
            self::generateEmail('testUserChangeNameSameAccount', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $bankAccount = new BankAccount();
        $bankAccount->setAccountName('f bar');
        $bankAccount->setMandateStatus(BankAccount::MANDATE_SUCCESS);
        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount($bankAccount);
        $user->setPaymentMethod($bacs);
        static::$dm->flush();

        $user->setFirstName('f');
        static::$dm->flush();

        $updatedUser = $this->assertUserExists(self::$container, $user);
        $this->assertEquals(
            BankAccount::MANDATE_SUCCESS,
            $updatedUser->getPaymentMethod()->getBankAccount()->getMandateStatus()
        );
    }

    public function testBankAccountChangedEvent()
    {
        self::$redis->flushdb();
        $this->assertEquals(0, self::$redis->hlen(BacsService::KEY_BACS_CANCEL));
        $bankAccount = new BankAccount();
        $bankAccount->setAccountName('f bar');
        $bankAccount->setAccountNumber('12345678');
        $bankAccount->setSortCode('000099');
        $bankAccount->setReference('123');
        $bacsEvent = new BacsEvent($bankAccount, '9');

        self::$bacsListener->onBankAccountChangedEvent($bacsEvent);

        $this->assertEquals(1, self::$redis->hlen(BacsService::KEY_BACS_CANCEL));
        $cancellations = self::$bacsService->getBacsCancellations();
        $data = $cancellations[0];
        $this->assertEquals($bankAccount->getSortCode(), $data['sortCode']);
        $this->assertEquals($bankAccount->getAccountName(), $data['accountName']);
        $this->assertEquals($bankAccount->getAccountNumber(), $data['accountNumber']);
        $this->assertEquals($bankAccount->getReference(), $data['reference']);
        $this->assertEquals('9', $data['id']);
    }
}
