<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Address;
use AppBundle\Document\Attribution;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Tests\UserClassTrait;

/**
 * @group unit
 */
class UserTest extends \PHPUnit\Framework\TestCase
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
        $this->assertEquals(1, count($user->getPolicies()));
        $this->assertEquals(1, count($user->getAllPolicies()));
    }

    public function testLastPasswordChange()
    {
        $user = new User();
        $user->setCreated(new \DateTime('2010-01-01'));
        $this->assertEquals(new \DateTime('2010-01-01'), $user->getLastPasswordChange());

        $user->passwordChange('a', 'b', new \DateTime('2011-01-01'));
        $this->assertEquals(new \DateTime('2011-01-01'), $user->getLastPasswordChange());

        $user->passwordChange('a', 'b', new \DateTime('2010-07-01'));
        $this->assertEquals(new \DateTime('2011-01-01'), $user->getLastPasswordChange());
    }

    public function testPasswordChangeRequired()
    {
        $user = new User();
        $this->assertFalse($user->isPasswordChangeRequired());
        $user->setCreated(new \DateTime('2010-01-01'));
        $this->assertFalse($user->isPasswordChangeRequired());
        $this->assertTrue($user->isCredentialsNonExpired());

        $user->passwordChange('a', 'b', new \DateTime('2011-01-01'));
        $this->assertFalse($user->isPasswordChangeRequired());
        $this->assertTrue($user->isCredentialsNonExpired());

        $user->addRole('ROLE_ADMIN');
        $this->assertTrue($user->isPasswordChangeRequired());
        $this->assertFalse($user->isCredentialsNonExpired());

        $eightyNineDaysAgo = new \DateTime();
        $eightyNineDaysAgo = $eightyNineDaysAgo->sub(new \DateInterval('P89D'));
        $user->passwordChange('a', 'b', $eightyNineDaysAgo);
        $this->assertFalse($user->isPasswordChangeRequired());
        $this->assertTrue($user->isCredentialsNonExpired());

        $user = new User();
        $user->addRole('ROLE_EMPLOYEE');
        $user->passwordChange('a', 'b', new \DateTime('2011-01-01'));
        $this->assertTrue($user->isPasswordChangeRequired());
        $this->assertFalse($user->isCredentialsNonExpired());

        $user = new User();
        $user->addRole('ROLE_CLAIMS');
        $user->passwordChange('a', 'b', new \DateTime('2011-01-01'));
        $this->assertTrue($user->isPasswordChangeRequired());
        $this->assertFalse($user->isCredentialsNonExpired());
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

    public function testCanRenewPolicy()
    {
        $policy = new PhonePolicy();
        $user = new User();
        $user->setLocked(true);
        $this->assertFalse($user->canRenewPolicy($policy));

        $user->setLocked(false);
        $user->setEnabled(false);
        $this->assertFalse($user->canRenewPolicy($policy));

        $user->setEnabled(true);
        $this->assertTrue($user->canRenewPolicy($policy));

        $policyB = new PhonePolicy();
        $user = new User();
        $user->setLocked(false);
        $user->setEnabled(true);
        $user->addPolicy($policyB);
        $this->assertTrue($user->canRenewPolicy($policyB));
        $policyB->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policyB->setCancelledReason(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD);
        $this->assertFalse($user->canRenewPolicy($policyB));

        $policyC = new PhonePolicy();
        $user = new User();
        $user->setLocked(false);
        $user->setEnabled(true);
        $user->addPolicy($policyC);
        $this->assertTrue($user->canRenewPolicy($policyC));
        $policyC->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policyC->setCancelledReason(SalvaPhonePolicy::CANCELLED_DISPOSSESSION);
        $this->assertFalse($user->canRenewPolicy($policyC));

        $policyD = new PhonePolicy();
        $user = new User();
        $user->setLocked(false);
        $user->setEnabled(true);
        $user->addPolicy($policyD);
        $this->assertTrue($user->canRenewPolicy($policyD));
        $policyD->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policyD->setCancelledReason(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);
        $this->assertTrue($user->canRenewPolicy($policyD));

        $policyE = new PhonePolicy();
        $user = new User();
        $user->setLocked(false);
        $user->setEnabled(true);
        $user->addPolicy($policyE);
        $this->assertTrue($user->canRenewPolicy($policyE));
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policyE->addClaim($claim);
        $this->assertTrue($user->canRenewPolicy($policyE));
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policyE->addClaim($claim);
        $this->assertTrue($user->canRenewPolicy($policyE));
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policyE->addClaim($claim);
        $this->assertFalse($user->canRenewPolicy($policyE));
    }

    public function testGetAvgPolicyClaims()
    {
        $user = new User();
        $this->assertEquals(0, $user->getAvgPolicyClaims());

        $policy = new PhonePolicy();
        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_COOLOFF);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        $user->addPolicy($policy);
        $this->assertEquals(0, $user->getAvgPolicyClaims());

        $policy = new PhonePolicy();
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        $user->addPolicy($policy);
        $this->assertEquals(1, $user->getAvgPolicyClaims());

        $policy = new PhonePolicy();
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        $user->addPolicy($policy);
        $this->assertEquals(1.5, $user->getAvgPolicyClaims());
    }

    public function testHasPolicyCancelledAndPaymentOwed()
    {
        $premium = new PhonePremium();
        $premium->setGwp(5);
        $premium->setIpt(1);
        $userA = new User();
        $policyA = new PhonePolicy();
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyA->setPremium($premium);
        $userA->addPolicy($policyA);
        $userB = new User();
        $policyB = new PhonePolicy();
        $policyB->setPremium($premium);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        $userB->addPolicy($policyB);
        $claimB = new Claim();
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policyB->addClaim($claimB);

        $userC = new User();
        $policyC1 = new PhonePolicy();
        $policyC1->setId(rand(1, 9999999));
        $policyC1->setPremium($premium);
        $policyC1->setStatus(Policy::STATUS_ACTIVE);
        $userC->addPolicy($policyC1);
        $policyC2 = new PhonePolicy();
        $policyC2->setId(rand(1, 9999999));
        $policyC2->setPremium($premium);
        $policyC2->setStatus(Policy::STATUS_ACTIVE);
        $userC->addPolicy($policyC2);
        $claimC = new Claim();
        $claimC->setStatus(Claim::STATUS_APPROVED);
        $policyC1->addClaim($claimC);

        $this->assertFalse($policyA->isCancelledAndPaymentOwed());
        $this->assertFalse($policyB->isCancelledAndPaymentOwed());
        $this->assertFalse($policyC1->isCancelledAndPaymentOwed());
        $this->assertFalse($policyC2->isCancelledAndPaymentOwed());

        $policyB->setStatus(Policy::STATUS_CANCELLED);
        $policyB->setCancelledReason(Policy::CANCELLED_UNPAID);
        $policyC1->setStatus(Policy::STATUS_CANCELLED);
        $policyC1->setCancelledReason(Policy::CANCELLED_UNPAID);

        $this->assertFalse($policyA->isCancelledAndPaymentOwed());
        $this->assertTrue($policyB->isCancelledAndPaymentOwed());
        $this->assertTrue($policyC1->isCancelledAndPaymentOwed());
        $this->assertFalse($policyC2->isCancelledAndPaymentOwed());

        $this->assertTrue($policyC1->getUser()->hasPolicyCancelledAndPaymentOwed());

        $policyC2->addLinkedClaim($claimC);

        $this->assertFalse($policyC1->isCancelledAndPaymentOwed());
        $this->assertFalse($policyC2->isCancelledAndPaymentOwed());

        $this->assertFalse($policyA->getUser()->hasPolicyCancelledAndPaymentOwed());
        $this->assertTrue($policyB->getUser()->hasPolicyCancelledAndPaymentOwed());
        $this->assertFalse($policyC1->getUser()->hasPolicyCancelledAndPaymentOwed());

        $policyC2->setStatus(Policy::STATUS_CANCELLED);
        $this->assertTrue($policyC1->isCancelledAndPaymentOwed());
        $this->assertTrue($policyC2->isCancelledAndPaymentOwed());

        $bacsA = new BacsPayment();
        $bacsA->setManual(true);
        $bacsA->setSuccess(true);
        $bacsA->setAmount($policyA->getPremium()->getMonthlyPremiumPrice() * 12);
        $policyA->addPayment($bacsA);
        $bacsB = new BacsPayment();
        $bacsB->setManual(true);
        $bacsB->setSuccess(true);
        $bacsB->setAmount($policyB->getPremium()->getMonthlyPremiumPrice() * 12);
        $policyB->addPayment($bacsB);

        $this->assertTrue($policyA->isFullyPaid());
        $this->assertTrue($policyB->isFullyPaid());

        $this->assertFalse($policyA->isCancelledAndPaymentOwed());
        $this->assertFalse($policyB->isCancelledAndPaymentOwed());

        $this->assertFalse($policyA->getUser()->hasPolicyCancelledAndPaymentOwed());
        $this->assertFalse($policyB->getUser()->hasPolicyCancelledAndPaymentOwed());
    }
}
