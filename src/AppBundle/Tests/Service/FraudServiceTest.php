<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Gocardless;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * @group functional-nonet
 */
class FraudServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $fraudService;
    protected static $dm;
    protected static $policyRepo;
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
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$policyRepo = self::$dm->getRepository(Policy::class);
         self::$userManager = self::$container->get('fos_user.user_manager');
         self::$fraudService = self::$container->get('app.fraud');
    }

    public function tearDown()
    {
    }

    public function testDuplicatePostcode()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user1', $this),
            'bar'
        );
        self::$dm->persist($user);

        $policy = static::createPolicy($user, static::$dm);

        // policy will set address
        $address = $user->getBillingAddress();
        $address->setPostcode('AB123');

        $user2 = static::createUser(
            static::$userManager,
            static::generateEmail('user2', $this),
            'bar'
        );
        $address2 = new Address();
        $address2->setType(Address::TYPE_BILLING);
        $address2->setPostcode('AB123');
        $user2->setBillingAddress($address2);
        self::$dm->persist($user2);
        self::$dm->flush();

        $data = self::$fraudService->runChecks($policy);
        $this->assertEquals(1, $data['duplicate_postcode']);
    }

    public function testDuplicateBankAccounts()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-bank1', $this),
            'bar'
        );
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostcode('AB123');
        $user->setBillingAddress($address);
        self::$dm->persist($user);
        self::$dm->flush();

        $account = json_encode(['account_hash' => '1234']);
        $gocardless = new Gocardless();
        $gocardless->addAccount('1', $account);
        $user->setGocardless($gocardless);
        $policy = static::createPolicy($user, static::$dm);

        $user2 = static::createUser(
            static::$userManager,
            static::generateEmail('user-bank2', $this),
            'bar'
        );
        $address2 = new Address();
        $address2->setType(Address::TYPE_BILLING);
        $address2->setPostcode('AB123');
        $user2->setBillingAddress($address2);
        $gocardless2 = new Gocardless();
        $gocardless2->addAccount('1', $account);
        $user2->setGocardless($gocardless2);
        $policy2 = static::createPolicy($user2, static::$dm);
        self::$dm->persist($user2);
        self::$dm->flush();

        $data = self::$fraudService->runChecks($policy);
        $this->assertEquals(1, $data['duplicate_bank_accounts']);
    }
}
