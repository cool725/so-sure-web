<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\SoSure;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Claim;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Policy;
use AppBundle\Document\User;

/**
 * @group unit
 * AppBundle\\Tests\\Document\\BankAccountTest
 */
class BankAccountTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testGetPaymentDate()
    {
        $bankAccount = new BankAccount();
        $this->assertEquals(
            new \DateTime('2018-03-09'),
            $bankAccount->getPaymentDate(new \DateTime('2018-03-05'))
        );
    }

    public function testGetPaymentDateWithMandate()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setMandateStatus(BankAccount::MANDATE_SUCCESS);
        $this->assertEquals(
            new \DateTime('2018-03-08'),
            $bankAccount->getPaymentDate(new \DateTime('2018-03-05'))
        );
    }

    public function testGetPaymentDatePost2pm()
    {
        $bankAccount = new BankAccount();
        $this->assertEquals(
            new \DateTime('2018-03-12 16:00'),
            $bankAccount->getPaymentDate(new \DateTime('2018-03-05 16:00'))
        );
    }

    public function testGetPaymentDateHoliday()
    {
        $bankAccount = new BankAccount();
        $this->assertEquals(
            new \DateTime('2018-04-05'),
            $bankAccount->getPaymentDate(new \DateTime('2018-03-28'))
        );
    }

    public function testGetFirstPaymentDateUnpaid()
    {
        $user = new User();
        $policy = new PhonePolicy();
        $premium = new PhonePremium();
        $premium->setGwp(5);
        $premium->setIpt(1);
        $premium->setIptRate(0.12);
        $policy->setPremium($premium);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart(new \DateTime('2018-03-05'));
        $policy->setPremiumInstallments(12);
        $bacsPayment = new BacsPayment();
        $bacsPayment->setAmount($premium->getMonthlyPremiumPrice());
        $bacsPayment->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsPayment->setSuccess(true);
        $bacsPayment->setDate(new \DateTime('2018-03-05'));
        //$policy->addPayment($bacsPayment);
        $user->addPolicy($policy);

        $this->assertFalse($policy->isPolicyPaidToDate(new \DateTime('2018-03-06')));

        $bankAccount = new BankAccount();
        $this->assertEquals(
            new \DateTime('2018-03-12'),
            $bankAccount->getFirstPaymentDate($user, new \DateTime('2018-03-06'))
        );
    }

    public function testGetFirstPaymentDatePaid()
    {
        $user = new User();
        $policy = new PhonePolicy();
        $premium = new PhonePremium();
        $premium->setGwp(5);
        $premium->setIpt(1);
        $premium->setIptRate(0.12);
        $policy->setPremium($premium);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart(new \DateTime('2018-03-05'));
        $policy->setPremiumInstallments(12);
        $bacsPayment = new BacsPayment();
        $bacsPayment->setAmount($premium->getMonthlyPremiumPrice());
        $bacsPayment->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsPayment->setSuccess(true);
        $bacsPayment->setDate(new \DateTime('2018-03-05'));
        $policy->addPayment($bacsPayment);
        $user->addPolicy($policy);

        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2018-03-06')));
        $this->assertEquals(
            new \DateTime('2018-04-05 00:00', new \DateTimeZone(SoSure::TIMEZONE)),
            $policy->getNextBillingDate(new \DateTime('2018-03-06'))
        );

        $bankAccount = new BankAccount();
        $this->assertEquals(
            new \DateTime('2018-04-05 00:00', new \DateTimeZone(SoSure::TIMEZONE)),
            $bankAccount->getFirstPaymentDate($user, new \DateTime('2018-03-06'))
        );
    }

    public function testGetFirstPaymentDateCloseToBacs()
    {
        $user = new User();
        $policy = new PhonePolicy();
        $premium = new PhonePremium();
        $premium->setGwp(5);
        $premium->setIpt(1);
        $premium->setIptRate(0.12);
        $policy->setPremium($premium);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart(new \DateTime('2018-03-05'));
        $policy->setPremiumInstallments(12);
        $bacsPayment = new BacsPayment();
        $bacsPayment->setAmount($premium->getMonthlyPremiumPrice());
        $bacsPayment->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsPayment->setSuccess(true);
        $bacsPayment->setDate(new \DateTime('2018-03-05'));
        $policy->addPayment($bacsPayment);
        $user->addPolicy($policy);

        $this->assertTrue($policy->isPolicyPaidToDate(new \DateTime('2018-04-04')));
        $this->assertEquals(
            new \DateTime('2018-04-05 00:00', new \DateTimeZone(SoSure::TIMEZONE)),
            $policy->getNextBillingDate(new \DateTime('2018-04-04'))
        );

        $bankAccount = new BankAccount();
        $this->assertEquals(
            new \DateTime('2018-04-10'),
            $bankAccount->getFirstPaymentDate($user, new \DateTime('2018-04-04'))
        );
    }

    public function testAllowedInitialProcessingSameDay()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setInitialNotificationDate(new \DateTime('2018-03-05'));
        $this->assertTrue(
            $bankAccount->allowedInitialProcessing(new \DateTime('2018-03-05'))
        );
    }

    public function testAllowedInitialProcessingAllowed()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setInitialNotificationDate(new \DateTime('2018-03-05'));
        $this->assertTrue(
            $bankAccount->allowedInitialProcessing(new \DateTime('2018-03-08'))
        );
    }

    public function testAllowedInitialProcessingNotAllowed()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setInitialNotificationDate(new \DateTime('2018-03-05'));
        $this->assertFalse(
            $bankAccount->allowedInitialProcessing(new \DateTime('2018-03-09'))
        );
    }

    public function testAllowedStandardProcessingSameDay()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setStandardNotificationDate(new \DateTime('2018-03-05'));
        $this->assertTrue(
            $bankAccount->allowedStandardProcessing(new \DateTime('2018-03-05'))
        );
    }

    public function testAllowedStandardProcessingAllowed()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setStandardNotificationDate(new \DateTime('2018-03-05'));
        $this->assertTrue(
            $bankAccount->allowedStandardProcessing(new \DateTime('2018-03-08'))
        );
    }

    public function testAllowedStandardProcessingNotAllowed()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setStandardNotificationDate(new \DateTime('2018-03-05'));
        $this->assertFalse(
            $bankAccount->allowedStandardProcessing(new \DateTime('2018-03-09'))
        );
    }

    public function testAllowedStandardProcessingEndOfMonthSameDay()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setStandardNotificationDate(new \DateTime('2018-02-28'));
        $this->assertTrue(
            $bankAccount->allowedStandardProcessing(new \DateTime('2018-02-28'))
        );
    }

    public function testAllowedStandardProcessingEndOfMonthAllowed()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setStandardNotificationDate(new \DateTime('2018-02-26'));
        $this->assertTrue(
            $bankAccount->allowedStandardProcessing(new \DateTime('2018-02-28'))
        );
    }

    public function testAllowedStandardProcessingXmas()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setStandardNotificationDate(new \DateTime('2018-12-21'));
        $this->assertTrue(
            $bankAccount->allowedStandardProcessing(new \DateTime('2018-12-28'))
        );
        $this->assertEquals(28, $bankAccount->getMaxAllowedStandardProcessingDay(new \DateTime('2018-12-28')));
    }

    public function testAllowedStandardProcessingBeginningOfMonthAllowed()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setStandardNotificationDate(new \DateTime('2018-02-26'));
        $this->assertTrue(
            $bankAccount->allowedStandardProcessing(new \DateTime('2018-03-01'))
        );
    }

    public function testAllowedStandardProcessingEndOfMonthNotAllowed()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setStandardNotificationDate(new \DateTime('2018-02-28'));
        $this->assertFalse(
            $bankAccount->allowedStandardProcessing(new \DateTime('2018-03-06'))
        );
    }

    public function testAllowedSubmission()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setInitialPaymentSubmissionDate(new \DateTime('2018-02-28 09:00'));
        $this->assertFalse($bankAccount->allowedSubmission(new \DateTime('2018-02-27 23:00')));
        $this->assertTrue($bankAccount->allowedSubmission(new \DateTime('2018-02-28 01:00')));
        $this->assertTrue($bankAccount->allowedSubmission(new \DateTime('2018-02-28 11:00')));
        $this->assertTrue($bankAccount->allowedSubmission(new \DateTime('2018-02-29 01:00')));
    }

    public function testReference()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setReference('ddic');
        $bankAccount->setReference('1DDIC');
        $bankAccount->setReference('Ok');
        $this->assertTrue(true);
    }

    /**
     * @expectedException \Exception
     */
    public function testReferenceDDIC()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setReference('DDIC0043242fa');
    }

    public function testShouldCancelMandate()
    {
        $date = \DateTime::createFromFormat('U', time());
        $thirteenMonths = \DateTime::createFromFormat('U', time());
        $thirteenMonths = $thirteenMonths->add(new \DateInterval('P13M'));

        $bankAccount = new BankAccount();

        // created
        $this->assertFalse($bankAccount->shouldCancelMandate());
        $this->assertFalse($bankAccount->shouldCancelMandate(new \DateTime('2016-01-01')));
        $this->assertTrue($bankAccount->shouldCancelMandate($thirteenMonths));

        // initialPaymentSubmissionDate
        $date = $date->add(new \DateInterval('P1M'));
        $bankAccount->setInitialPaymentSubmissionDate($date);
        $this->assertFalse($bankAccount->shouldCancelMandate());
        $this->assertFalse($bankAccount->shouldCancelMandate(new \DateTime('2016-01-01')));
        $this->assertFalse($bankAccount->shouldCancelMandate($thirteenMonths));
        $thirteenMonths = $thirteenMonths->add(new \DateInterval('P1M'));
        $this->assertTrue($bankAccount->shouldCancelMandate($thirteenMonths));

        // lastSuccessfulPaymentDate
        $date = $date->add(new \DateInterval('P1M'));
        $bankAccount->getLastSuccessfulPaymentDate();
        $this->assertFalse($bankAccount->shouldCancelMandate());
        $this->assertFalse($bankAccount->shouldCancelMandate(new \DateTime('2016-01-01')));
        $this->assertFalse($bankAccount->shouldCancelMandate($thirteenMonths));
        $thirteenMonths = $thirteenMonths->add(new \DateInterval('P1M'));
        $this->assertTrue($bankAccount->shouldCancelMandate($thirteenMonths));
    }

    public function testAllowedProcessingAllowed()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setFirstPayment(true);
        $bankAccount->setInitialNotificationDate(new \DateTime('2018-03-05'));
        $this->assertTrue(
            $bankAccount->allowedProcessing(new \DateTime('2018-03-08'))
        );

        $thirteenMonths = \DateTime::createFromFormat('U', time());
        $thirteenMonths = $thirteenMonths->add(new \DateInterval('P13M'));
        $this->assertFalse($bankAccount->allowedProcessing($thirteenMonths));
    }

    public function testIsBeforeAfterInitialNotificationDate()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setInitialNotificationDate(new \DateTime('2018-03-05'));

        $this->assertFalse($bankAccount->isAfterInitialNotificationDate(new \DateTime('2018-03-04 00:01')));
        $this->assertTrue($bankAccount->isBeforeInitialNotificationDate(new \DateTime('2018-03-04 00:01')));

        $this->assertTrue($bankAccount->isAfterInitialNotificationDate(new \DateTime('2018-03-05 00:01')));
        $this->assertFalse($bankAccount->isBeforeInitialNotificationDate(new \DateTime('2018-03-05 00:01')));

        // after 15:00 should be the day before as bacs should have run
        $this->assertTrue($bankAccount->isAfterInitialNotificationDate(new \DateTime('2018-03-04 15:01')));
        $this->assertFalse($bankAccount->isBeforeInitialNotificationDate(new \DateTime('2018-03-04 15:01')));
    }
}
