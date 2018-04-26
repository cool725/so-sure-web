<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\User;
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

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $xmlFile;
    protected static $bacsService;

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
        self::$bacsService = self::$container->get('app.bacs');
        self::$userManager = self::$container->get('fos_user.user_manager');
    }

    public function tearDown()
    {
    }

    public function testBacsXml()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testBacsXml', $this),
            'bar'
        );
        $this->setValidBacsPaymentMethod($user, 'SOSURE01');
        static::$dm->flush();

        $results = self::$bacsService->addacs(self::$xmlFile);
        $this->assertTrue($results['success']);

        $updatedUser = $this->assertUserExists(self::$container, $user);
        $this->assertEquals(
            BankAccount::MANDATE_CANCELLED,
            $updatedUser->getPaymentMethod()->getBankAccount()->getMandateStatus()
        );
    }

    private function setValidBacsPaymentMethod(User $user, $reference = null)
    {
        $bankAccount = new BankAccount();
        $bankAccount->setMandateStatus(BankAccount::MANDATE_SUCCESS);
        if (!$reference) {
            $reference = sprintf('SOSURE%d', rand(1, 999999));
        }
        $bankAccount->setReference($reference);
        $bankAccount->setSortCode('000099');
        $bankAccount->setAccountNumber('87654321');
        $bankAccount->setAccountName($user->getName());
        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount($bankAccount);
        $user->setPaymentMethod($bacs);
    }

    public function testBacsPayment()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testBacsPayment', $this));
        $this->setValidBacsPaymentMethod($policy->getUser());
        $payment = static::$bacsService->bacsPayment($policy, 'test', 1.01);
        $this->assertEquals(1.01, $payment->getAmount());
        $this->assertEquals('test', $payment->getNotes());
        $this->assertNull($payment->isSuccess());
    }
}
