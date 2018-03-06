<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\Salva;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\CurrencyTrait;

/**
 * @group unit
 */
class JudoPaymentTest extends \PHPUnit\Framework\TestCase
{
    use CurrencyTrait;

    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testResult()
    {
        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $this->assertEquals(JudoPayment::RESULT_SUCCESS, $payment->getResult());
        $this->assertTrue($payment->isSuccess());

        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_DECLINED);
        $this->assertEquals(JudoPayment::RESULT_DECLINED, $payment->getResult());
        $this->assertFalse($payment->isSuccess());

        $payment = new JudoPayment();
        $payment->setResult(JudoPayment::RESULT_SKIPPED);
        $this->assertEquals(JudoPayment::RESULT_SKIPPED, $payment->getResult());
        $this->assertFalse($payment->isSuccess());
    }

    /**
     * @expectedException \Exception
     */
    public function testResultUnknown()
    {
        $payment = new JudoPayment();
        $payment->setResult('foo');
    }
}
