<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\Premium;
use AppBundle\Classes\Salva;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\CurrencyTrait;

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
        $this->assertEquals(new \DateTime('2018-01-01'), $bacs->getSubmittedDate());
        $this->assertEquals(new \DateTime('2018-01-03'), $bacs->getBacsCreditDate());
        $this->assertEquals(new \DateTime('2018-01-08'), $bacs->getBacsReversedDate());
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
        $this->assertTrue($bacs->canAction(new \DateTime('2018-01-08')));
        $this->assertTrue($bacs->canAction(new \DateTime('2018-02-01')));
        $this->assertFalse($bacs->canAction(new \DateTime('2018-01-07')));

        $bacs->setStatus(BacsPayment::STATUS_FAILURE);
        $this->assertFalse($bacs->canAction(new \DateTime('2018-01-08')));

        $bacs->setStatus(BacsPayment::STATUS_SUCCESS);
        $this->assertFalse($bacs->canAction(new \DateTime('2018-01-08')));
    }

    public function testApprove()
    {
        $bacs = new BacsPayment();
        $bacs->setAmount(6);
        $bacs->submit(new \DateTime('2018-01-01'));
        $bacs->setStatus(BacsPayment::STATUS_GENERATED);

        $policy = new PhonePolicy();

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $policy->addPayment($bacs);
        $bacs->approve();

        $this->assertEquals(Bacs::MANDATE_SUCCESS, $bacs->getStatus());
        $this->assertTrue($bacs->isSuccess());
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
        $bacs = new BacsPayment();
        $bacs->setAmount(6);
        $bacs->submit(new \DateTime('2018-01-01'));
        $bacs->setStatus(BacsPayment::STATUS_GENERATED);

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
