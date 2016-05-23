<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\JudoPaymentMethod;

/**
 * @group functional-net
 */
class JudopayServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $judopay;
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
         self::$judopay = self::$container->get('app.judopay');
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
    
    public function testJudo()
    {
        $user = $this->createValidUser(static::generateEmail('judo', $this));
        $policy = static::createPolicy($user, static::$dm);
        self::$judopay->add($policy, 'a', 'b', 'c');
    }

    public function testJudoReceipt()
    {
        $user = $this->createValidUser(static::generateEmail('judo-receipt', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            '122',
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );
        $payment = self::$judopay->validateReceipt($policy, 'token', $receiptId);
        $this->assertEquals($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(), $payment->getAmount());
        $this->assertEquals($receiptId, $payment->getReceipt());
        $this->assertEquals('122', $payment->getReference());
        $this->assertEquals('Success', $payment->getResult());

        $tokens = $user->getPaymentMethod()->getCardTokens();
        $this->assertEquals(1, count($tokens));
        $data = json_decode($tokens['token']);
        $this->assertEquals('3436', $data->cardLastfour);
        $this->assertEquals('1220', $data->endDate);
    }

    /**
     * @expectedException \Exception
     */
    public function testJudoReceiptPaymentDiffException()
    {
        $user = $this->createValidUser(static::generateEmail('judo-receipt-exception', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken('ctoken');
        $judo->addCardToken('token', null);
        $user->setPaymentMethod($judo);
        static::$dm->flush();

        $receiptId = self::$judopay->testPay(
            $user,
            '122',
            '1.01',
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );
        $payment = self::$judopay->validateReceipt($policy, 'token', $receiptId);
    }

    public function testJudoAdd()
    {
        $user = $this->createValidUser(static::generateEmail('judo-add', $this));
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::createPolicy($user, static::$dm, $phone);

        $receiptId = self::$judopay->testPay(
            $user,
            '122',
            $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );
        self::$judopay->add($policy, 'ctoken', 'token', $receiptId);

        $this->assertEquals(PhonePolicy::STATUS_PENDING, $policy->getStatus());
        $this->assertGreaterThan(1, $policy->getPolicyNumber());
    }
}
