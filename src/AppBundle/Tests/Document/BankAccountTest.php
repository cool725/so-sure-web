<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\BankAccount;
use AppBundle\Document\Claim;

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
}
