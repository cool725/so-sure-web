<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\Premium;
use AppBundle\Classes\Salva;
use AppBundle\Document\Form\Imei;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\User;
use AppBundle\Event\PolicyEvent;
use AppBundle\Service\BacsService;
use AppBundle\Tests\Create;

/**
 * @group unit
 */
class BacsPaymentTest extends \PHPUnit\Framework\TestCase
{
    use CurrencyTrait;

    public function testSubmit()
    {
        $user = Create::user();
        $policy = Create::policy($user, '2018-01-01', Policy::STATUS_ACTIVE, 12);
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

        $policy = new HelvetiaPhonePolicy();
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
        $policy = new HelvetiaPhonePolicy();
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

        $policy = new HelvetiaPhonePolicy();

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

        $policy = new HelvetiaPhonePolicy();

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
        $policy = new SalvaPhonePolicy();
        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);
        $policy->addPayment($bacs);
        $bacs->reject();
    }

    /**
     * Makes sure that if a user owes more than a month worth of cash then they will not be set to active after a single
     * payment.
     */
    public function testRemainUnpaidIfVeryLate()
    {
        $premium = new PhonePremium();
        $premium->setGwp(100);
        $premium->setIpt(1);
        $policy = new SalvaPhonePolicy();
        $policy->setStatus(PhonePolicy::STATUS_UNPAID);
        $policy->setPremium($premium);
        $policy->setPremiumInstallments(12);
        $policy->setStart(new \DateTime('2018-02-01'));
        $policy->setBilling(new \DateTime('2018-02-01'));
        // one payment late.
        $bacs = new BacsPayment();
        $bacs->setPolicy($policy);
        $bacs->setSubmittedDate(new \DateTime('2018-03-02'));
        $bacs->setAmount($premium->getMonthlyPremiumPrice());
        $this->assertEquals(PhonePolicy::STATUS_UNPAID, $bacs->getPolicy()->getStatus());
        $bacs->submit(new \DateTime('2018-03-02'));
        $this->assertEquals(PhonePolicy::STATUS_UNPAID, $bacs->getPolicy()->getStatus());
        // another one.
        // Technically we can not test multiple payments in a unit test. As a workaround, just double the value of this
        // payment to pretend it is two payments.
        $bacs = new BacsPayment();
        $bacs->setPolicy($policy);
        $bacs->setSubmittedDate(new \DateTime('2018-03-10'));
        $bacs->setAmount($premium->getMonthlyPremiumPrice() * 2);
        $this->assertEquals(PhonePolicy::STATUS_UNPAID, $bacs->getPolicy()->getStatus());
        $bacs->submit(new \DateTime('2018-03-10'));
        $this->assertEquals(PhonePolicy::STATUS_ACTIVE, $bacs->getPolicy()->getStatus());
    }

    public function testApproveSetsCorrectCommission()
    {
        $user = Create::user();
        $policy = Create::bacsPolicy($user, '2018-01-01', Policy::STATUS_ACTIVE, 12);
        for ($i = 0; $i < 11; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_PENDING);

            $bacs = new BacsPayment();
            $bacs->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
            $dateString = sprintf("2018-%s-01", mb_substr("0$i", -2));
            $bacs->submit(new \DateTime($dateString));
            $bacs->setStatus(BacsPayment::STATUS_GENERATED);
            $bacs->setScheduledPayment($scheduledPayment);
            $scheduledPayment->setPayment($bacs);

            $policy->addPayment($bacs);
            self::assertNotEquals(Salva::MONTHLY_TOTAL_COMMISSION, $bacs->getTotalCommission());
            $bacs->approve();

            $this->assertEquals(Bacs::MANDATE_SUCCESS, $bacs->getStatus());
            $this->assertTrue($bacs->isSuccess());
            $this->assertNotNull($bacs->getScheduledPayment());
            if ($bacs->getScheduledPayment()) {
                $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $bacs->getScheduledPayment()->getStatus());
            }
            self::assertEquals(Salva::MONTHLY_TOTAL_COMMISSION, $bacs->getTotalCommission());
        }

        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_PENDING);

        $bacs = new BacsPayment();
        $bacs->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $bacs->submit(new \DateTime('2018-12-01'));
        $bacs->setStatus(BacsPayment::STATUS_GENERATED);
        $bacs->setScheduledPayment($scheduledPayment);
        $scheduledPayment->setPayment($bacs);
        $policy->addPayment($bacs);
        self::assertNotEquals(Salva::MONTHLY_TOTAL_COMMISSION, $bacs->getTotalCommission());
        self::assertNotEquals(Salva::FINAL_MONTHLY_TOTAL_COMMISSION, $bacs->getTotalCommission());
        $bacs->approve();
        $this->assertEquals(Bacs::MANDATE_SUCCESS, $bacs->getStatus());
        $this->assertTrue($bacs->isSuccess());
        $this->assertNotNull($bacs->getScheduledPayment());
        if ($bacs->getScheduledPayment()) {
            $this->assertEquals(ScheduledPayment::STATUS_SUCCESS, $bacs->getScheduledPayment()->getStatus());
        }
        self::assertEquals(Salva::FINAL_MONTHLY_TOTAL_COMMISSION, $bacs->getTotalCommission());
        $now = \DateTime::createFromFormat('U', time());
        /** @var BacsPaymentMethod $paymentMethod */
        $paymentMethod = $policy->getPaymentMethod();
        $this->assertEquals($now, $paymentMethod->getBankAccount()->getLastSuccessfulPaymentDate(), '', 1);
    }
}
