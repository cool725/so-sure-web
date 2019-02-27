<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
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
    /** @var DocumentManager */
    protected static $dm;
    protected static $fraudService;
    protected static $policyRepo;

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

        $policy = static::initPolicy($user, static::$dm, static::getRandomPhone(static::$dm), null, false, true);

        // policy will set address
        $address = $user->getBillingAddress();
        $address->setPostcode('se15 2sn');

        $user2 = static::createUser(
            static::$userManager,
            static::generateEmail('user2', $this),
            'bar'
        );
        $address2 = new Address();
        $address2->setType(Address::TYPE_BILLING);
        $address2->setPostcode('se15 2sn');
        $user2->setBillingAddress($address2);
        self::$dm->persist($user2);
        self::$dm->flush();

        $data = self::$fraudService->runChecks($policy);
        $this->assertEquals(1, $data['duplicate_postcode']);
    }

    public function testDuplicateJudoBankAccounts()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('judo1', $this),
            'bar'
        );
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostcode('BX11LT');
        $user->setBillingAddress($address);
        self::$dm->persist($user);
        self::$dm->flush();

        $account = ['type' => '1', 'lastfour' => '1234', 'exp' => '1220'];
        $judo = new JudoPaymentMethod();
        $judo->addCardToken('1', $account);
        $policy = static::initPolicy($user, static::$dm, static::getRandomPhone(static::$dm), null, false, true);
        $policy->setPaymentMethod($judo);

        $user2 = static::createUser(
            static::$userManager,
            static::generateEmail('judo2', $this),
            'bar'
        );
        $address2 = new Address();
        $address2->setType(Address::TYPE_BILLING);
        $address2->setPostcode('BX11LT');
        $user2->setBillingAddress($address2);

        $judo2 = new JudoPaymentMethod();
        $judo2->addCardToken('1', $account);
        $policy2 = static::initPolicy($user2, static::$dm, static::getRandomPhone(static::$dm), null, false, true);
        $policy2->setPaymentMethod($judo2);
        self::$dm->persist($user2);
        self::$dm->flush();

        $data = self::$fraudService->runChecks($policy);
        $this->assertEquals(1, $data['duplicate_bank_accounts_count']);
    }
}
