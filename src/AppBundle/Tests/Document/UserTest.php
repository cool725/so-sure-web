<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Attribution;
use AppBundle\Document\SalvaPhonePolicy;
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

    public function testAllowedMonthlyPayments()
    {
        $user = new User();

        $this->assertFalse($user->allowedMonthlyPayments());

        $user->setFirstName('foo');
        $user->setLastName('bar');
        $user->setMobileNumber('+447777711111');
        $user->setBirthday(new \DateTime("1980-01-01"));
        $user->setEmail('foo@bar.com');

        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1('123 foo rd');
        $address->setCity('London');
        $address->setPostcode('ec1v 1rx');
        $user->setBillingAddress($address);

        $this->assertTrue($user->allowedMonthlyPayments());

        $address->setPostcode('de14 2sz');
        $this->assertFalse($user->allowedMonthlyPayments());

        $address->setPostcode('TN15 7LY');
        $this->assertFalse($user->allowedMonthlyPayments());
    }

    public function testAllowedYearlyPayments()
    {
        $user = new User();

        $this->assertFalse($user->allowedMonthlyPayments());

        $user->setFirstName('foo');
        $user->setLastName('bar');
        $user->setMobileNumber('+447777711111');
        $user->setBirthday(new \DateTime("1980-01-01"));
        $user->setEmail('foo@bar.com');

        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1('123 foo rd');
        $address->setCity('London');
        $address->setPostcode('ec1v 1rx');
        $user->setBillingAddress($address);

        $this->assertTrue($user->allowedYearlyPayments());

        $address->setPostcode('de14 2sz');
        $this->assertTrue($user->allowedYearlyPayments());

        $address->setPostcode('TN15 7LY');
        $this->assertTrue($user->allowedYearlyPayments());
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

    public function testHasCancelledPolicyWithUserDeclined()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD);
        $user->addPolicy($policy);
        $this->assertTrue($user->hasCancelledPolicyWithUserDeclined());
    }

    public function testGetPoliciesUserDeclined()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD);
        $this->assertEquals(0, count($user->getPolicies()));
        $user->addPolicy($policy);
        $this->assertEquals(1, count($user->getPolicies()));
    }

    public function testGetPoliciesUserOk()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_COOLOFF);
        $this->assertEquals(0, count($user->getPolicies()));
        $user->addPolicy($policy);
        $this->assertEquals(0, count($user->getPolicies()));
        $this->assertEquals(1, count($user->getAllPolicies()));
    }

    public function testHasUnpaidPolicy()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setStatus(SalvaPhonePolicy::STATUS_UNPAID);
        $user->addPolicy($policy);
        $this->assertTrue($user->hasUnpaidPolicy());
    }

    public function testHasValidPolicy()
    {
        $user = new User();
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        $user->addPolicy($policy);
        $this->assertTrue($user->hasActivePolicy());
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

    public function testHasSoSureEmail()
    {
        $user = new User();
        $user->setEmailCanonical('foo@so-sure.com');
        $this->assertTrue($user->hasSoSureEmail());

        $user2 = new User();
        $user2->setEmailCanonical('foo@notsosure.com');
        $this->assertFalse($user2->hasSoSureEmail());
    }

    public function testImageUrlFacebook()
    {
        $user = new User();
        $user->setFacebookId('1');
        $this->assertEquals('https://graph.facebook.com/1/picture?width=2&height=2', $user->getImageUrl(2));
    }

    public function testImageUrlLetter()
    {
        $user = new User();
        $user->setEmail('foo@bar.com');
        $user->setFirstName('Foo');
        // @codingStandardsIgnoreStart
        $this->assertEquals(
            'https://www.gravatar.com/avatar/f3ada405ce890b6f8204094deb12d8a8?d=404&s=1',
            $user->getImageUrl(1)
        );
        // @codingStandardsIgnoreEnd
    }

    public function testImageUrlUnknown()
    {
        $user = new User();
        $user->setEmail('foo@bar.com');
        $this->assertEquals(
            'https://www.gravatar.com/avatar/f3ada405ce890b6f8204094deb12d8a8?d=404&s=1',
            $user->getImageUrl(1)
        );
    }

    public function testAttribution()
    {
        $user = new User();
        $this->assertNull($user->getAttribution());

        $attribution = new Attribution();
        $attribution->setCampaignName('foo');
        $user->setAttribution($attribution);
        $this->assertEquals('foo', $user->getAttribution()->getCampaignName());

        $attribution = new Attribution();
        $attribution->setCampaignName('bar');
        $user->setAttribution($attribution);
        $this->assertEquals('bar', $user->getAttribution()->getCampaignName());
    }

    public function testLatestAttribution()
    {
        $user = new User();
        $this->assertNull($user->getLatestAttribution());

        $attribution = new Attribution();
        $attribution->setCampaignName('foo');
        $user->setLatestAttribution($attribution);
        $this->assertEquals('foo', $user->getLatestAttribution()->getCampaignName());

        $attribution = new Attribution();
        $attribution->setCampaignName('bar');
        $user->setLatestAttribution($attribution);
        $this->assertEquals('bar', $user->getLatestAttribution()->getCampaignName());
    }
}
