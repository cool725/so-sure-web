<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\Form\PicSureStatus;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Tests\UserClassTrait;
use AppBundle\Tests\Create;
use DateTime;
use Symfony\Bridge\PhpUnit\ClockMock;

/**
 * @group unit
 */
class ClaimTest extends \PHPUnit\Framework\TestCase
{
    use UserClassTrait;

    public function testSetStatus()
    {
        $claim = new Claim();
        $claim->setType(Claim::TYPE_WARRANTY);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $this->assertNull($claim->getApprovedDate());
    }

    public function testSetStatusExtended()
    {
        $claim = new Claim();
        $claim->setType(Claim::TYPE_EXTENDED_WARRANTY);
        $claim->setStatus(Claim::STATUS_APPROVED);

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testSetStatusSettled()
    {
        $claim = new Claim();
        $claim->setType(Claim::TYPE_EXTENDED_WARRANTY);
        $claim->setStatus(Claim::STATUS_SETTLED);

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testRisk()
    {
        $user = Create::user();
        $policy = Create::policy($user, '2020-01-01', Policy::STATUS_ACTIVE, 12);
        $claim = Create::claim($policy, Claim::TYPE_LOSS, '2020-01-01', Claim::STATUS_FNOL);
        // less than 6 months old no picsure -> black
        $claim->setNotificationDate(new \DateTime('2020-01-01'));
        $this->assertEquals(Claim::RISK_BLACK, $claim->getRisk());
        // less than 12 months old no picsure -> black
        $claim->setNotificationDate(new \DateTime('2020-06-02'));
        $this->assertEquals(Claim::RISK_BLACK, $claim->getRisk());
        // more than 12 months old no picsure -> green
        $claim->setNotificationDate(new \DateTime('2021-01-02'));
        $this->assertEquals(Claim::RISK_GREEN, $claim->getRisk());
        // less than 6 months old picsure -> red
        $claim->setFnolPicSureValidated(true);
        $claim->setNotificationDate(new \DateTime('2020-01-01'));
        $this->assertEquals(Claim::RISK_RED, $claim->getRisk());
        // less than 12 months old picsure -> amber
        $claim->setNotificationDate(new \DateTime('2020-07-01'));
        $this->assertEquals(Claim::RISK_AMBER, $claim->getRisk());
        // more than 12 months old picsure -> green
        $claim->setNotificationDate(new \DateTime('2021-01-02'));
        $this->assertEquals(Claim::RISK_GREEN, $claim->getRisk());
    }
}
