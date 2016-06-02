<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePrice;
use AppBundle\Document\PhonePolicy;
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

        $phonePolicy = new PhonePolicy();
        $phonePolicy->setPremium($premium);

        $payment = new JudoPayment();
        $payment->setAmount(5);
        $payment->setPolicy($phonePolicy);
        $payment->calculateIpt();
        $this->assertEquals(0.48, $this->toTwoDp($payment->getIpt()));
    }
}
