<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PolicyTerms;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Tests\UserClassTrait;
use AppBundle\Classes\Salva;

/**
 * @group functional-nonet
 */
class PaymentFunctionalTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use UserClassTrait;

    protected static $container;
    protected static $dm;
    protected static $phone;

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
    }

    public function tearDown()
    {
    }

    /**
     * @expectedException \MongoDuplicateKeyException
     */
    public function testDuplicatePayment()
    {
        $paymentA = new JudoPayment();
        $paymentA->setReceipt(1);
        static::$dm->persist($paymentA);
        static::$dm->flush();

        $paymentB = new JudoPayment();
        $paymentB->setReceipt(1);
        static::$dm->persist($paymentB);
        static::$dm->flush();
    }
}
