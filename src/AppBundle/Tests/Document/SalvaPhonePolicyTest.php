<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePolicy;
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
class SalvaPhonePolicyTest extends WebTestCase
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
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
    }

    public function tearDown()
    {
    }

    public function testGetSalvaVersion()
    {
        $policy = new PhonePolicy();
        $policy->setStart(new \DateTime('2016-01-01'));
        $this->assertNull($policy->getSalvaVersion(new \DateTime('2016-01-01')));
        
        $policy->incrementSalvaPolicyNumber(new \DateTime('2016-02-01'));
        $this->assertEquals(1, $policy->getSalvaVersion(new \DateTime('2016-01-01')));
        // current version is null
        $this->assertNull($policy->getSalvaVersion(new \DateTime('2016-02-01 00:01')));
    }

    public function testIncrementSalvaPolicyNumber()
    {
        $policy = new PhonePolicy();
        $policy->setStart(new \DateTime('2016-01-01'));
        $this->assertEquals(0, count($policy->getSalvaPolicyNumbers()));
        
        $policy->incrementSalvaPolicyNumber(new \DateTime('2016-02-01'));
        $this->assertEquals(1, count($policy->getSalvaPolicyNumbers()));

        $policy->incrementSalvaPolicyNumber(new \DateTime('2016-03-01'));
        $this->assertEquals(2, count($policy->getSalvaPolicyNumbers()));
    }

    public function testSalvaTerminationDate()
    {
        $policy = new PhonePolicy();
        $policy->setStart(new \DateTime('2016-01-01'));
        $this->assertNull($policy->getSalvaTerminationDate());

        $policy->incrementSalvaPolicyNumber(new \DateTime('2016-02-01'));
        $this->assertEquals(new \DateTime('2016-02-01'), $policy->getSalvaTerminationDate(1));
        $this->assertNull($policy->getSalvaTerminationDate());
    }

    public function testGetSalvaStartDate()
    {
        $policy = new PhonePolicy();
        $policy->setStart(new \DateTime('2016-01-01'));
        $this->assertEquals(new \DateTime('2016-01-01'), $policy->getSalvaStartDate());

        $policy->incrementSalvaPolicyNumber(new \DateTime('2016-02-01'));
        $this->assertEquals(new \DateTime('2016-02-01'), $policy->getSalvaStartDate());
        $this->assertEquals(new \DateTime('2016-01-01'), $policy->getSalvaStartDate(1));
    }

    public function testGetTotalPremiumPrices()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(self::$phone);
        $payments = [1,2];
        $this->assertEquals(83.88, $policy->getTotalPremiumPrice());
        $this->assertEquals(13.98, $policy->getTotalPremiumPrice($payments));
        $this->assertEquals(69.90, $policy->getRemainingTotalPremiumPrice($payments));
    }

    public function testGetTotalGwpPrices()
    {
        $policy = new PhonePolicy();
        $policy->setPhone(self::$phone);
        $payments = [1,2];
        $this->assertEquals(76.60, $policy->getTotalGwp());
        $this->assertEquals(12.76, $policy->getTotalGwp($payments));
        $this->assertEquals(63.84, $policy->getRemainingTotalGwp($payments));
    }
}
