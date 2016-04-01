<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Policy;

/**
 * @group functional-nonet
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

    public function testCreateCustomer()
    {
        $user = static::createUser(self::$userManager, 'user1@gocardless.so-sure.com', 'foo');
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1('10 Finsbury Square');
        $address->setCity('London');
        $address->setPostcode('EC1V 1RS');
        $user->addAddress($address);

        static::$dm->persist($address);
        static::$dm->flush();

        self::$gocardless->createCustomer($user);
        $this->assertTrue(strlen($user->getGocardless()->getCustomerId()) > 5);
    }

    public function testAddBankAccount()
    {
        $user = static::createUser(self::$userManager, 'user2@gocardless.so-sure.com', 'foo');
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1('10 Finsbury Square');
        $address->setCity('London');
        $address->setPostcode('EC1V 1RS');
        $user->addAddress($address);

        static::$dm->persist($address);
        static::$dm->flush();

        self::$gocardless->createCustomer($user);
        $this->assertTrue(strlen($user->getGocardless()->getCustomerId()) > 5);
        self::$gocardless->addBankAccount($user, '200000', '55779911');
        $this->assertTrue(count($user->getGocardless()->getAccounts()) > 0);
        $accountDetail = $user->getGocardless()->getPrimaryAccount();
        $this->assertEquals('11', $accountDetail->account_number_ending);
    }

    public function testCreateMandate()
    {
        $user = static::$userRepo->findOneBy(['email' => 'user2@gocardless.so-sure.com']);
        $policy = new Policy();
        $policy->setUser($user);

        static::$dm->persist($policy);
        static::$dm->flush();

        self::$gocardless->createMandate($policy);
        
    }
}
