<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\Premium;
use AppBundle\Classes\Salva;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\User;
use AppBundle\Event\PolicyEvent;
use AppBundle\Service\BacsService;

/**
 * @group unit
 */
class BacsPaymentTest extends \PHPUnit\Framework\TestCase
{
    use CurrencyTrait;

    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testSubmit()
    {
        $bacs = new BacsPayment();
        $bacs->submit(new \DateTime('2018-01-01'));
        $this->assertEquals(new \DateTime('2018-01-01'), $bacs->getDate());
        $this->assertEquals(new \DateTime('2018-01-01'), $bacs->getSubmittedDate());
        $this->assertEquals(new \DateTime('2018-01-03'), $bacs->getBacsCreditDate());
        $this->assertEquals(new \DateTime('2018-01-08'), $bacs->getBacsReversedDate());

        $bacs = new BacsPayment();
        $bacs->setSubmittedDate(new \DateTime('2018-02-01'));
        $bacs->submit(new \DateTime('2018-01-01'));
        $this->assertEquals(new \DateTime('2018-02-01'), $bacs->getDate());
        $this->assertEquals(new \DateTime('2018-02-01'), $bacs->getSubmittedDate());
        $this->assertEquals(new \DateTime('2018-02-05'), $bacs->getBacsCreditDate());
        $this->assertEquals(new \DateTime('2018-02-08'), $bacs->getBacsReversedDate());

        $bacs = new BacsPayment();
        $bacs->setSubmittedDate(new \DateTime('2018-01-01'));
        $bacs->submit(new \DateTime('2018-02-01'));
        $this->assertEquals(new \DateTime('2018-02-01'), $bacs->getDate());
        $this->assertEquals(new \DateTime('2018-02-01'), $bacs->getSubmittedDate());
        $this->assertEquals(new \DateTime('2018-02-05'), $bacs->getBacsCreditDate());
        $this->assertEquals(new \DateTime('2018-02-08'), $bacs->getBacsReversedDate());

        $policy = new PhonePolicy();
        $policy->setStatus(PhonePolicy::STATUS_UNPAID);
        $bacs = new BacsPayment();
        $bacs->setPolicy($policy);
        $bacs->setSubmittedDate(new \DateTime('2018-01-01'));

        $this->assertEquals(PhonePolicy::STATUS_UNPAID, $bacs->getPolicy()->getStatus());
        $bacs->submit(new \DateTime('2018-02-01'));
        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $bacs->getPolicy()->getStatus());
    }

    public function testInProgress()
    {
        $bacs = new BacsPayment();
        $bacs->setStatus(BacsPayment::STATUS_FAILURE);
        $this->assertFalse($bacs->inProgress());
        $bacs->setStatus(BacsPayment::STATUS_SUCCESS);
        $this->assertFalse($bacs->inProgress());

        $bacs->setStatus(BacsPayment::STATUS_SUBMITTED);
        $this->assertTrue($bacs->inProgress());
        $bacs->setStatus(BacsPayment::STATUS_GENERATED);
        $this->assertTrue($bacs->inProgress());
        $bacs->setStatus(BacsPayment::STATUS_PENDING);
        $this->assertTrue($bacs->inProgress());
    }

    public function testCanAction()
    {
        $bacs = new BacsPayment();
        $bacs->submit(new \DateTime('2018-01-01'));
        $bacs->setStatus(BacsPayment::STATUS_GENERATED);
        $this->assertTrue($bacs->canAction(BacsPayment::ACTION_APPROVE, new \DateTime('2018-01-08')));
        $this->assertTrue($bacs->canAction(BacsPayment::ACTION_APPROVE, new \DateTime('2018-02-01')));
        $this->assertFalse($bacs->canAction(BacsPayment::ACTION_APPROVE, new \DateTime('2018-01-07')));

        $bacs->setStatus(BacsPayment::STATUS_FAILURE);
        $this->assertFalse($bacs->canAction(BacsPayment::ACTION_APPROVE, new \DateTime('2018-01-08')));

        $bacs->setStatus(BacsPayment::STATUS_SUCCESS);
        $this->assertFalse($bacs->canAction(BacsPayment::ACTION_APPROVE, new \DateTime('2018-01-08')));
    }

    public function testApprove()
    {
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_PENDING);

        $bankAccount = new BankAccount();
        $bacsPaymentMethod = new BacsPaymentMethod();
        $bacsPaymentMethod->setBankAccount($bankAccount);
        $user = new User();
        $policy = new PhonePolicy();
        $user->addPolicy($policy);

        $bacs = new BacsPayment();
        $bacs->setAmount(6);
        $bacs->submit(new \DateTime('2018-01-01'));
        $bacs->setStatus(BacsPayment::STATUS_GENERATED);
        $bacs->setScheduledPayment($scheduledPayment);
        $scheduledPayment->setPayment($bacs);

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $policy->setPaymentMethod($bacsPaymentMethod);
        $policy->addPayment($bacs);
        $now = \DateTime::createFromFormat('U', time());
        $bacs->approve();

        $this->assertEquals(Bacs::MANDATE_SUCCESS, $bacs->getStatus());
        $this->assertTrue($bacs->isSuccess());
        $this->assertNotNull($bacs->getScheduledPayment());
        if ($bacs->getScheduledPayment()) {
            $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $bacs->getScheduledPayment()->getStatus());
        }

        $this->assertEquals($now, $bankAccount->getLastSuccessfulPaymentDate(), '', 1);
    }

    /**
     * @expectedException \Exception
     */
    public function testApproveCanNotAction()
    {
        $bacs = new BacsPayment();
        $bacs->setAmount(6);
        $bacs->submit(new \DateTime('2018-01-01'));
        $bacs->setStatus(BacsPayment::STATUS_SUCCESS);

        $policy = new PhonePolicy();

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $policy->addPayment($bacs);
        $bacs->approve();
    }

    public function testReject()
    {
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_PENDING);

        $bacs = new BacsPayment();
        $bacs->setAmount(6);
        $bacs->submit(new \DateTime('2018-01-01'));
        $bacs->setStatus(BacsPayment::STATUS_GENERATED);
        $bacs->setScheduledPayment($scheduledPayment);
        $scheduledPayment->setPayment($bacs);

        $policy = new PhonePolicy();

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $policy->addPayment($bacs);
        $bacs->reject();

        $this->assertEquals(Bacs::MANDATE_FAILURE, $bacs->getStatus());
        $this->assertFalse($bacs->isSuccess());
        $this->assertNotNull($bacs->getScheduledPayment());
        if ($bacs->getScheduledPayment()) {
            $this->assertEquals(ScheduledPayment::STATUS_FAILED, $bacs->getScheduledPayment()->getStatus());
        }
    }

    /**
     * @expectedException \Exception
     */
    public function testRejectCanNotAction()
    {
        $bacs = new BacsPayment();
        $bacs->setAmount(6);
        $bacs->submit(new \DateTime('2018-01-01'));
        $bacs->setStatus(BacsPayment::STATUS_FAILURE);

        $policy = new PhonePolicy();

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $policy->addPayment($bacs);
        $bacs->reject();
    }
}
