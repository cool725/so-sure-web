<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Tests\UserClassTrait;
use AppBundle\Document\CurrencyTrait;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests the behaviour of the policy document.
 * @group functional-nonet
 */
class PolicyTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use UserClassTrait;
    use DateTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;

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
    }

    /**
     * Tests if the get scheduled payment refunds method works correctly.
     */
    public function testGetScheduledPaymentRefunds()
    {
        // Set up the data.
        $nNonRefunds = rand(5, 50);
        $nRefunds = rand(5, 50);
        $nonRefundAmount = rand(0, 100) / 90;
        $refundAmount = rand(0, 100) / 90;
        $date = new \DateTime();
        $policy = self::createUserPolicy(
            true,
            $this->subDays($date, 60),
            false,
            uniqid()."@gmail.com",
            $this->generateRandomImei()
        );
        for ($i = 0; $i < $nNonRefunds; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setType(ScheduledPayment::TYPE_SCHEDULED);
            $scheduledPayment->setAmount($nonRefundAmount);
            $scheduledPayment->setScheduled($this->addDays($date, rand(-50, 50)));
            $policy->addScheduledPayment($scheduledPayment);
        }
        $refunds = [];
        for ($i = 0; $i < $nRefunds; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setType(ScheduledPayment::TYPE_REFUND);
            $scheduledPayment->setAmount($refundAmount);
            $scheduledPayment->setScheduled($this->addDays($date, rand(0, 50)));
            $policy->addScheduledPayment($scheduledPayment);
            $refunds[] = $scheduledPayment;
        }
        // now see
        $foundRefunds = $policy->getScheduledPaymentRefunds();
        foreach ($foundRefunds as $refund) {
            $this->assertContains($refund, $refunds);
        }
        $this->assertEquals($nRefunds, count($foundRefunds));
    }

    /**
     * Tests if the get scheduled payment refunds method works correctly.
     */
    public function testGetScheduledPaymentRefundAmount()
    {
        // Set up the data.
        $nNonRefunds = rand(5, 50);
        $nRefunds = rand(5, 50);
        $nonRefundAmount = rand(0, 100) / 90;
        $refundAmount = rand(-100, -1) / 90;
        $date = new \DateTime();
        $policy = self::createUserPolicy(
            true,
            $this->subDays($date, 60),
            false,
            uniqid()."@gmail.com",
            $this->generateRandomImei()
        );
        for ($i = 0; $i < $nNonRefunds; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setType(ScheduledPayment::TYPE_SCHEDULED);
            $scheduledPayment->setAmount($nonRefundAmount);
            $scheduledPayment->setScheduled($this->addDays($date, rand(-50, 50)));
            $policy->addScheduledPayment($scheduledPayment);
        }
        for ($i = 0; $i < $nRefunds; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setType(ScheduledPayment::TYPE_REFUND);
            $scheduledPayment->setAmount($refundAmount);
            $scheduledPayment->setScheduled($this->addDays($date, rand(1, 50)));
            $policy->addScheduledPayment($scheduledPayment);
        }
        // Now check if it works.
        $this->assertEquals(abs($nRefunds * $refundAmount), $policy->getScheduledPaymentRefundAmount());
    }
}
