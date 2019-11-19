<?php

namespace AppBundle\Tests\Repository;

use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Tests\UserClassTrait;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests that the scheduled payment repository methods work as they are intended.
 * @group functional-nonet
 */
class ScheduledPaymentRepositoryTest extends KernelTestCase
{
    use UserClassTrait;
    use DateTrait;

    /** @var ScheduledPaymentRepository */
    private $scheduledPaymentRepo;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $kernel = self::bootKernel();
        /** @var DocumentManager */
        $dm = $kernel->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        /** @var ScheduledPaymentRepository */
        $scheduledPaymentRepo = self::$dm->getRepository(ScheduledPayment::class);
        $this->scheduledPaymentRepo = $scheduledPaymentRepo;
    }

    /**
     * Makes sure that unpaid scheduled payments are counted correctly for both bacs and checkout regardless of the
     * status of the last actual payment.
     */
    public function testCountUnpaidScheduledPayments()
    {
        // Two after no successful scheduled payments.
        $a = $this->eligiblePolicy("2019-01-01");
        $this->makeScheduledPayment($a, ScheduledPayment::TYPE_RESCHEDULED, false, "2019-02-03");
        $this->makeScheduledPayment($a, ScheduledPayment::TYPE_RESCHEDULED, false, "2019-02-10");
        // One after a successful scheduled payment.
        $b = $this->eligiblePolicy("2019-01-01");
        $this->makeScheduledPayment($b, ScheduledPayment::TYPE_SCHEDULED, true, "2018-08-03");
        $this->makeScheduledPayment($b, ScheduledPayment::TYPE_RESCHEDULED, false, "2018-08-04");
        // Three after a successful rescheduled payment.
        $c = $this->eligiblePolicy("2019-01-01");
        $this->makeScheduledPayment($c, ScheduledPayment::TYPE_RESCHEDULED, false, "2019-08-01");
        $this->makeScheduledPayment($c, ScheduledPayment::TYPE_RESCHEDULED, true, "2019-08-07");
        $this->makeScheduledPayment($c, ScheduledPayment::TYPE_RESCHEDULED, false, "2019-08-14");
        $this->makeScheduledPayment($c, ScheduledPayment::TYPE_RESCHEDULED, false, "2019-08-21");
        $this->makeScheduledPayment($c, ScheduledPayment::TYPE_RESCHEDULED, false, "2019-08-28");
        // Zero when there is nothing there.
        $d = $this->eligiblePolicy("2019-01-01");
        // Zero when there are successful payments.
        $e = $this->eligiblePolicy("2019-01-01");
        $this->makeScheduledPayment($e, ScheduledPayment::TYPE_SCHEDULED, true, "2019-02-01");
        $this->makeScheduledPayment($e, ScheduledPayment::TYPE_RESCHEDULED, false, "2019-02-01");
        $this->makeScheduledPayment($e, ScheduledPayment::TYPE_RESCHEDULED, true, "2019-02-01");
        // test them all
        self::$dm->flush();
        $this->assertEquals(2, $this->scheduledPaymentRepo->countUnpaidScheduledPayments($a));
        $this->assertEquals(1, $this->scheduledPaymentRepo->countUnpaidScheduledPayments($b));
        $this->assertEquals(3, $this->scheduledPaymentRepo->countUnpaidScheduledPayments($c));
        $this->assertEquals(0, $this->scheduledPaymentRepo->countUnpaidScheduledPayments($d));
        $this->assertEquals(0, $this->scheduledPaymentRepo->countUnpaidScheduledPayments($d));
    }

    /**
     * Creates a policy with a start and end date.
     * @param string $dateString is the date that the policy starts as a string.
     */
    private static function eligiblePolicy($dateString)
    {
        $start = new \DateTime($dateString);
        $policy = new PhonePolicy();
        $policy->setStart($start);
        $policy->setEnd((clone $start)->add(new \DateInterval("P1Y")));
        $policy->setPolicyNumber("VAGRANT/2019/".rand());
        self::$dm->persist($policy);
        return $policy;
    }

    /**
     * Makes a scheduled payment on the given policy.
     * @param Policy  $policy     is the policy to put the scheduled payment on.
     * @param string  $type       is the type value to give the scheduled payment.
     * @param boolean $success    is the success value to give to the scheduled payment.
     * @param string  $dateString is the string value to create the payment's scheduled date with.
     * @return ScheduledPayment the scheduled payment that was created.
     */
    private static function makeScheduledPayment($policy, $type, $success, $dateString)
    {
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setType($type);
        $scheduledPayment->setStatus($success ? ScheduledPayment::STATUS_SUCCESS : ScheduledPayment::STATUS_FAILED);
        $scheduledPayment->setScheduled(new \DateTime($dateString));
        $policy->addScheduledPayment($scheduledPayment);
        self::$dm->persist($scheduledPayment);
        return $scheduledPayment;
    }
}
