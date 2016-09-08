<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePrice;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\CurrencyTrait;

/**
 * @group unit
 */
class PaymentTest extends \PHPUnit_Framework_TestCase
{
    use CurrencyTrait;

    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testCalculatePremium()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2016-05-01');
        $premium = $phonePrice->createPremium($date);

        $phonePolicy = new SalvaPhonePolicy();
        $phonePolicy->setPremium($premium);

        $payment = new JudoPayment();
        $payment->setAmount(5);
        $payment->setPolicy($phonePolicy);
        $payment->calculateSplit();
        $this->assertEquals(4.53, $this->toTwoDp($payment->getGwp()));
        $this->assertEquals(0.48, $this->toTwoDp($payment->getIpt()));
    }
}
