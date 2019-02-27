<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\SoSure;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;

/**
 * @group unit
 */
class BacsPaymentMethodTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testSetBankAccount()
    {
        $bacs = new BacsPaymentMethod();
        $this->assertCount(0, $bacs->getPreviousBankAccounts());
        $bankAccount1 = new BankAccount();
        $bankAccount1->setBankName('foo');
        $bacs->setBankAccount($bankAccount1);
        $this->assertCount(1, $bacs->getPreviousBankAccounts());

        $bacs->setBankAccount($bankAccount1);
        $this->assertCount(1, $bacs->getPreviousBankAccounts());

        $bankAccount2 = new BankAccount();
        $bankAccount2->setBankName('bar');
        $bacs->setBankAccount($bankAccount2);
        $this->assertCount(2, $bacs->getPreviousBankAccounts());
    }
}
