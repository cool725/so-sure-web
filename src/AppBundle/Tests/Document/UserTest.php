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

    public function testHasValidGocardlessDetails()
    {
        $user = new User();
        $this->assertFalse($user->hasValidGocardlessDetails());

        $user->setFirstName('foo');
        $this->assertFalse($user->hasValidGocardlessDetails());

        $user->setLastName('bar');
        $this->assertFalse($user->hasValidGocardlessDetails());

        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $user->addAddress($address);
        $this->assertFalse($user->hasValidGocardlessDetails());

        $address->setLine1('123 foo rd');
        $this->assertFalse($user->hasValidGocardlessDetails());

        $address->setCity('London');
        $this->assertFalse($user->hasValidGocardlessDetails());

        $address->setPostcode('ec1v 1rx');
        $this->assertTrue($user->hasValidGocardlessDetails());
    }

    public function testReplaceBillingAddress()
    {
        $user = new User();
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostcode('123');
        $user->addAddress($address);

        $billingAddress = $user->getBillingAddress();
        $user->removeAddress($billingAddress);

        $address2 = new Address();
        $address2->setType(Address::TYPE_BILLING);
        $address->setPostcode('456');
        $user->addAddress($address);

        $this->assertEquals('456', $user->getBillingAddress()->getPostcode());
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
}
