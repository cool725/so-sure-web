<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Tests\UserClassTrait;

/**
 * @group unit
 */
class UserTest extends \PHPUnit_Framework_TestCase
{
    use UserClassTrait;

    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testHasValidDetails()
    {
        $user = new User();
        $this->assertFalse($user->hasValidDetails());

        $user->setFirstName('foo');
        $this->assertFalse($user->hasValidDetails());

        $user->setLastName('bar');
        $this->assertFalse($user->hasValidDetails());

        $user->setMobileNumber('+447777711111');
        $this->assertFalse($user->hasValidDetails());

        $user->setBirthday(new \DateTime("1980-01-01"));
        $this->assertFalse($user->hasValidDetails());

        $user->setEmail('foo@bar.com');
        $this->assertTrue($user->hasValidDetails());

        $user->setBirthday(new \DateTime("1800-01-01"));
        $this->assertFalse($user->hasValidDetails());

        $now = new \DateTime();
        $user->setBirthday(new \DateTime(sprintf("%d-01-01", $now->format('Y'))));
        $this->assertFalse($user->hasValidDetails());
    }

    public function testHasValidBillingDetails()
    {
        $user = new User();
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $user->setBillingAddress($address);
        $this->assertFalse($user->hasValidBillingDetails());

        $address->setLine1('123 foo rd');
        $this->assertFalse($user->hasValidBillingDetails());

        $address->setCity('London');
        $this->assertFalse($user->hasValidBillingDetails());

        $address->setPostcode('ec1v 1rx');
        $this->assertTrue($user->hasValidBillingDetails());
    }

    public function testHasCancelledPolicy()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        $policy->setStatus(PhonePolicy::STATUS_CANCELLED);
        $user->addPolicy($policy);
        $this->assertTrue($user->hasCancelledPolicy());
    }

    public function testHasUnpaidPolicy()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        $policy->setStatus(PhonePolicy::STATUS_UNPAID);
        $user->addPolicy($policy);
        $this->assertTrue($user->hasUnpaidPolicy());
    }

    public function testHasValidPolicy()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new PhonePolicy();
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        $user->addPolicy($policy);
        $this->assertTrue($user->hasValidPolicy());
    }

    public function testMobileNumberIsNormalized()
    {
        $userA = new User();
        $userA->setMobileNumber('07700 900000');
        $this->assertEquals('+447700900000', $userA->getMobileNumber());

        $userB = new User();
        $userB->setMobileNumber('00447700 900000');
        $this->assertEquals('+447700900000', $userB->getMobileNumber());
    }

    public function testUnprocessedReceivedInvitations()
    {
        $user = new User();
        $invitiationA = new EmailInvitation();
        $user->addReceivedInvitation($invitiationA);
        $this->assertEquals(1, count($user->getUnprocessedReceivedInvitations()));

        $invitiationB = new EmailInvitation();
        $invitiationB->setAccepted(true);
        $user->addReceivedInvitation($invitiationB);
        $this->assertEquals(1, count($user->getUnprocessedReceivedInvitations()));
    }

    public function testTestUser()
    {
        $user = new User();
        $user->setEmailCanonical('foo@so-sure.com');
        $this->assertTrue($user->isTestUser());

        $user2 = new User();
        $user2->setEmailCanonical('foo@notsosure.com');
        $this->assertFalse($user2->isTestUser());
    }
}
