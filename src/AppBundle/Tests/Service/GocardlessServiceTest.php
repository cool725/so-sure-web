<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\PhonePolicy;

/**
 * @group functional-net
 */
class GocardlessServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $gocardless;
    protected static $dm;
    protected static $userRepo;
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
         self::$gocardless = self::$container->get('app.gocardless');
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$userRepo = self::$dm->getRepository(User::class);
         self::$userManager = self::$container->get('fos_user.user_manager');
    }

    public function tearDown()
    {
    }
    
    private function createValidUser($email)
    {
        $user = static::createUser(self::$userManager, $email, 'foo');
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $user->setMobileNumber(static::generateRandomMobile());
        $user->setBirthday(new \DateTime('1980-01-01'));
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1('10 Finsbury Square');
        $address->setCity('London');
        $address->setPostcode('EC1V 1RS');
        $user->setBillingAddress($address);

        static::$dm->persist($address);
        static::$dm->flush();

        return $user;
    }

    public function testCreateCustomer()
    {
        $user = $this->createValidUser('user1@gocardless.so-sure.com');

        self::$gocardless->createCustomer($user, $user->getFirstName(), $user->getLastName());
        $this->assertTrue(strlen($user->getPaymentMethod()->getCustomerId()) > 5);
    }

    public function testAddBankAccount()
    {
        $user = $this->createValidUser('user2@gocardless.so-sure.com');

        self::$gocardless->createCustomer($user, $user->getFirstName(), $user->getLastName());
        $this->assertTrue(strlen($user->getPaymentMethod()->getCustomerId()) > 5);

        self::$gocardless->addBankAccount($user, '200000', '55779911');
        $this->assertTrue(count($user->getPaymentMethod()->getAccounts()) > 0);
        $this->assertTrue(count($user->getPaymentMethod()->getAccountHashes()) > 0);

        $accountDetail = $user->getPaymentMethod()->getPrimaryAccount();
        $this->assertEquals('11', $accountDetail->account_number_ending);
        $this->assertTrue(in_array($accountDetail->account_hash, $user->getPaymentMethod()->getAccountHashes()));
    }

    public function testAddBankAccountWithHyphens()
    {
        $user = $this->createValidUser('user3@gocardless.so-sure.com');

        self::$gocardless->createCustomer($user, $user->getFirstName(), $user->getLastName());
        $this->assertTrue(strlen($user->getPaymentMethod()->getCustomerId()) > 5);

        self::$gocardless->addBankAccount($user, '20-00-00', '55779911');
        $this->assertTrue(count($user->getPaymentMethod()->getAccounts()) > 0);

        $accountDetail = $user->getPaymentMethod()->getPrimaryAccount();
        $this->assertEquals('11', $accountDetail->account_number_ending);
    }
    
    public function testCreateMandate()
    {
        $user = static::$userRepo->findOneBy(['email' => 'user2@gocardless.so-sure.com']);
        $policy = new PhonePolicy();
        $policy->setUser($user);

        static::$dm->persist($policy);
        static::$dm->flush();

        self::$gocardless->createMandate($policy);
        
    }
}
