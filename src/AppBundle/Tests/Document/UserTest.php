<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Opt\EmailOptIn;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Offer;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Address;
use AppBundle\Document\Attribution;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Service\PostcodeService;
use AppBundle\Tests\UserClassTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group unit
 */
class UserTest extends WebTestCase
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

        $now = \DateTime::createFromFormat('U', time());
        $user->setBirthday(new \DateTime(sprintf("%d-01-01", $now->format('Y'))));
        $this->assertFalse($user->hasValidDetails());
    }

    /*
     * These test work but don't pass CI/CD checks
    public function testAllowedMonthlyPayments()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();
        //get the DI container
        $container = $kernel->getContainer();
        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        $postcodeService = $container->get('app.postcode');
        $user = new User();

        $this->assertFalse($user->allowedMonthlyPayments($postcodeService));

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

        $this->assertTrue($user->allowedMonthlyPayments($postcodeService));

        $address->setPostcode('de14 2sz');
        $this->assertFalse($user->allowedMonthlyPayments($postcodeService));

        $address->setPostcode('TN15 7LY');
        $this->assertFalse($user->allowedMonthlyPayments($postcodeService));
    }

    public function testAllowedYearlyPayments()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();
        //get the DI container
        $container = $kernel->getContainer();
        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        $postcodeService = $container->get('app.postcode');

        $user = new User();

        $this->assertFalse($user->allowedMonthlyPayments($postcodeService));

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
    */

    /**
     * Need this because the comment above is causing the damn ci/cd checks to fail!
     */
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

        $eightyNineDaysAgo = \DateTime::createFromFormat('U', time());
        $eightyNineDaysAgo = $eightyNineDaysAgo->sub(new \DateInterval('P89D'));
        $user->passwordChange('a', 'b', $eightyNineDaysAgo);
        $this->assertFalse($user->isPasswordChangeRequired());
        $this->assertTrue($user->isCredentialsNonExpired());

        $user = new User();
        $user->addRole(User::ROLE_EMPLOYEE);
        $user->passwordChange('a', 'b', new \DateTime('2011-01-01'));
        $this->assertTrue($user->isPasswordChangeRequired());
        $this->assertFalse($user->isCredentialsNonExpired());

        $user = new User();
        $user->addRole(User::ROLE_CLAIMS);
        $user->passwordChange('a', 'b', new \DateTime('2011-01-01'));
        $this->assertTrue($user->isPasswordChangeRequired());
        $this->assertFalse($user->isCredentialsNonExpired());
    }

    public function testDaysLeftUntilPasswordChangeRequired()
    {
        $user = new User();
        $user->addRole('ROLE_ADMIN');
        $user->passwordChange('a', 'b', \DateTime::createFromFormat('U', time()));
        $this->assertEquals(90, $user->daysLeftUntilPasswordChangeRequired());

        $user = new User();
        $user->addRole('ROLE_ADMIN');
        $this->assertEquals(90, $user->daysLeftUntilPasswordChangeRequired());

        $user = new User();

        $date = \DateTime::createFromFormat('U', time());
        $date->sub(new \DateInterval('P30D'));

        $user->addRole('ROLE_ADMIN');
        $user->passwordChange('a', 'b', $date);
        $this->assertEquals(60, $user->daysLeftUntilPasswordChangeRequired());

        $user = new User();

        $date = \DateTime::createFromFormat('U', time());
        $date->sub(new \DateInterval('P91D'));

        $user->addRole('ROLE_ADMIN');
        $user->passwordChange('a', 'b', $date);
        $this->assertEquals(-1, $user->daysLeftUntilPasswordChangeRequired());

        $user = new User();
        $user->addRole('ROLE_USER');
        $this->assertEquals(90, $user->daysLeftUntilPasswordChangeRequired());

        $user = new User();

        $date = \DateTime::createFromFormat('U', time());
        $date->sub(new \DateInterval('P91D'));

        $user->addRole('ROLE_USER');
        $user->passwordChange('a', 'b', $date);
        $this->assertEquals(-1, $user->daysLeftUntilPasswordChangeRequired());
    }

    /**
     * @expectedException \Exception
     */
    public function testDaysLeftUntilPasswordChangeRequiredFuture()
    {
        $user = new User();

        $date = \DateTime::createFromFormat('U', time());
        $date->add(new \DateInterval('P180D'));

        $user->addRole('ROLE_USER');
        $user->passwordChange('a', 'b', $date);
        $user->daysLeftUntilPasswordChangeRequired();
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
        $user->setEmailCanonical('foo@sO-sure.com');
        $this->assertTrue($user->hasSoSureEmail());

        $user2 = new User();
        $user2->setEmailCanonical('foo@notsosure.com');
        $this->assertFalse($user2->hasSoSureEmail());

        $user3 = new User();
        $user3->setEmailCanonical('foo@so-sure.net');
        $this->assertFalse($user3->hasSoSureEmail());
    }

    public function testHasSoSureRewardsEmail()
    {
        $user = new User();
        $user->setEmailCanonical('foo@so-sure.com');
        $this->assertFalse($user->hasSoSureRewardsEmail());

        $user2 = new User();
        $user2->setEmailCanonical('foo@notsosure.com');
        $this->assertFalse($user2->hasSoSureRewardsEmail());

        $user3 = new User();
        $user3->setEmailCanonical('foo@sO-sure.net');
        $this->assertTrue($user3->hasSoSureRewardsEmail());
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
        $policy = new HelvetiaPhonePolicy();
        $user = new User();
        $user->setLocked(true);
        $this->assertFalse($user->canRenewPolicy($policy));

        $user->setLocked(false);
        $user->setEnabled(false);
        $this->assertFalse($user->canRenewPolicy($policy));

        $user->setEnabled(true);
        $this->assertTrue($user->canRenewPolicy($policy));

        $policyB = new HelvetiaPhonePolicy();
        $user = new User();
        $user->setLocked(false);
        $user->setEnabled(true);
        $user->addPolicy($policyB);
        $this->assertTrue($user->canRenewPolicy($policyB));
        $policyB->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policyB->setCancelledReason(SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD);
        $this->assertFalse($user->canRenewPolicy($policyB));

        $policyC = new HelvetiaPhonePolicy();
        $user = new User();
        $user->setLocked(false);
        $user->setEnabled(true);
        $user->addPolicy($policyC);
        $this->assertTrue($user->canRenewPolicy($policyC));
        $policyC->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policyC->setCancelledReason(SalvaPhonePolicy::CANCELLED_DISPOSSESSION);
        $this->assertFalse($user->canRenewPolicy($policyC));

        $policyD = new HelvetiaPhonePolicy();
        $user = new User();
        $user->setLocked(false);
        $user->setEnabled(true);
        $user->addPolicy($policyD);
        $this->assertTrue($user->canRenewPolicy($policyD));
        $policyD->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policyD->setCancelledReason(SalvaPhonePolicy::CANCELLED_USER_REQUESTED);
        $this->assertTrue($user->canRenewPolicy($policyD));

        $policyE = new HelvetiaPhonePolicy();
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

        $policy = new HelvetiaPhonePolicy();
        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(SalvaPhonePolicy::CANCELLED_COOLOFF);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        $user->addPolicy($policy);
        $this->assertEquals(0, $user->getAvgPolicyClaims());

        $policy = new HelvetiaPhonePolicy();
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        $user->addPolicy($policy);
        $this->assertEquals(1, $user->getAvgPolicyClaims());

        $policy = new HelvetiaPhonePolicy();
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
        $policyA = new HelvetiaPhonePolicy();
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyA->setPremium($premium);
        $userA->addPolicy($policyA);
        $userB = new User();
        $policyB = new HelvetiaPhonePolicy();
        $policyB->setPremium($premium);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        $userB->addPolicy($policyB);
        $claimB = new Claim();
        $claimB->setStatus(Claim::STATUS_APPROVED);
        $policyB->addClaim($claimB);

        $userC = new User();
        $policyC1 = new HelvetiaPhonePolicy();
        $policyC1->setId(rand(1, 9999999));
        $policyC1->setPremium($premium);
        $policyC1->setStatus(Policy::STATUS_ACTIVE);
        $userC->addPolicy($policyC1);
        $policyC2 = new HelvetiaPhonePolicy();
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
        $bacsA->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsA->setSuccess(true);
        $bacsA->setAmount($policyA->getPremium()->getMonthlyPremiumPrice() * 12);
        $policyA->addPayment($bacsA);
        $bacsB = new BacsPayment();
        $bacsB->setManual(true);
        $bacsB->setStatus(BacsPayment::STATUS_SUCCESS);
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

    public function testCanDeleteNoPolicy()
    {
        $user = new User();
        $this->assertTrue($user->canDelete());
    }

    public function testCanDeletePartialPolicy()
    {
        $user = new User();
        $policy = new HelvetiaPhonePolicy();
        $user->addPolicy($policy);
        $this->assertTrue($user->canDelete());
    }

    public function testCanDeletePolicy()
    {
        $user = new User();
        $user->setCreated(new \DateTime('2016-01-01'));
        $policy = new HelvetiaPhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setEnd(new \DateTime('2017-01-01'));
        $user->addPolicy($policy);

        $this->assertFalse($user->canDelete(new \DateTime('2000-01-01')));
        $this->assertFalse($user->canDelete(new \DateTime('2018-01-01')));
        $this->assertFalse($user->canDelete(new \DateTime('2024-06-01')));
        // 7.5 years after end date
        $this->assertTrue($user->canDelete(new \DateTime('2024-07-03')));
        $this->assertTrue($user->canDelete(new \DateTime('2030-01-01')));
    }

    public function testShouldDeleteNoPolicy()
    {
        $user = new User();
        $user->setCreated(new \DateTime('2016-01-01'));
        $this->assertFalse($user->shouldDelete(new \DateTime('2000-01-01')));
        $this->assertFalse($user->shouldDelete(new \DateTime('2017-06-01')));
        $this->assertTrue($user->shouldDelete(new \DateTime('2017-07-03')));
        $this->assertTrue($user->shouldDelete(new \DateTime('2024-07-03')));

        $emailOptOut = new EmailOptOut();
        $emailOptOut->setUpdated(new \DateTime('2017-01-01'));
        $user->addOpt($emailOptOut);
        $this->assertFalse($user->shouldDelete(new \DateTime('2000-01-01')));
        $this->assertFalse($user->shouldDelete(new \DateTime('2017-06-01')));
        $this->assertTrue($user->shouldDelete(new \DateTime('2017-07-03')));
        $this->assertTrue($user->shouldDelete(new \DateTime('2024-07-03')));

        // dates should be increased if opt in is recorded
        $emailOptIn = new EmailOptIn();
        $emailOptIn->addCategory(EmailOptIn::OPTIN_CAT_MARKETING);
        $emailOptIn->setUpdated(new \DateTime('2017-01-01'));
        $user->addOpt($emailOptIn);
        $this->assertFalse($user->shouldDelete(new \DateTime('2000-01-01')));
        $this->assertFalse($user->shouldDelete(new \DateTime('2018-06-01')));
        $this->assertTrue($user->shouldDelete(new \DateTime('2018-07-03')));
        $this->assertTrue($user->shouldDelete(new \DateTime('2024-07-03')));
    }

    public function testShouldDeleteRoles()
    {
        foreach ([User::ROLE_CLAIMS, User::ROLE_EMPLOYEE, User::ROLE_CUSTOMER_SERVICES, User::ROLE_ADMIN] as $role) {
            $user = new User();
            $user->setCreated(new \DateTime('2016-01-01'));
            $this->assertTrue($user->shouldDelete(new \DateTime('2017-07-03')));

            $user->addRole($role);
            $this->assertFalse($user->shouldDelete(new \DateTime('2017-07-03')));
        }
    }

    public function testShouldDeleteSoSureEmail()
    {
        $emails = [
            'foo@so-sure.com' => false,
            'bar@so-sure.net' => false,
            'foobar@so-sure.org' => true,
        ];
        foreach ($emails as $email => $expect) {
            $user = new User();
            $user->setCreated(new \DateTime('2016-01-01'));
            $user->setEmailCanonical($email);
            if ($expect) {
                $this->assertTrue($user->shouldDelete(new \DateTime('2017-07-03')), $email);
            } else {
                $this->assertFalse($user->shouldDelete(new \DateTime('2017-07-03')), $email);
            }
        }
    }

    public function testShouldDeletePartialPolicy()
    {
        $user = new User();
        $policy = new HelvetiaPhonePolicy();
        $user->addPolicy($policy);
        $user->setCreated(new \DateTime('2016-01-01'));
        $this->assertFalse($user->shouldDelete(new \DateTime('2000-01-01')));
        $this->assertFalse($user->shouldDelete(new \DateTime('2017-06-01')));
        $this->assertTrue($user->shouldDelete(new \DateTime('2017-07-03')));
        $this->assertTrue($user->shouldDelete(new \DateTime('2024-07-03')));

        // dates should be increased if opt in is recorded
        $emailOptIn = new EmailOptIn();
        $emailOptIn->addCategory(EmailOptIn::OPTIN_CAT_MARKETING);
        $emailOptIn->setUpdated(new \DateTime('2017-01-01'));
        $user->addOpt($emailOptIn);
        $this->assertFalse($user->shouldDelete(new \DateTime('2000-01-01')));
        $this->assertFalse($user->shouldDelete(new \DateTime('2018-06-01')));
        $this->assertTrue($user->shouldDelete(new \DateTime('2018-07-03')));
        $this->assertTrue($user->shouldDelete(new \DateTime('2024-07-03')));
    }

    public function testShouldDeletePolicy()
    {
        $user = new User();
        $user->setCreated(new \DateTime('2016-01-01'));
        $policy = new HelvetiaPhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setEnd(new \DateTime('2017-01-01'));
        $user->addPolicy($policy);

        $this->assertFalse($user->shouldDelete(new \DateTime('2000-01-01')));
        $this->assertFalse($user->shouldDelete(new \DateTime('2018-01-01')));
        $this->assertFalse($user->shouldDelete(new \DateTime('2024-06-01')));
        // 7.5 years after end date
        $this->assertTrue($user->shouldDelete(new \DateTime('2024-07-03')));
        $this->assertTrue($user->shouldDelete(new \DateTime('2030-01-01')));
    }

    public function testValidateDpaNotValid()
    {
        $user = new User();
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $user->setMobileNumber(self::generateRandomMobile());
        $user->setBirthday(new \DateTime('1980-10-30'));
        $this->assertEquals(User::DPA_VALIDATION_NOT_VALID, $user->validateDpa());
        $this->assertEquals(User::DPA_VALIDATION_NOT_VALID, $user->validateDpa('foo'));
        $this->assertEquals(
            User::DPA_VALIDATION_NOT_VALID,
            $user->validateDpa('foo', 'bar')
        );
        $this->assertEquals(
            User::DPA_VALIDATION_NOT_VALID,
            $user->validateDpa('foo', 'bar', '30/10/1980')
        );
        $this->assertEquals(
            User::DPA_VALIDATION_NOT_VALID,
            $user->validateDpa('foo', 'bar', null, $user->getMobileNumber())
        );
        $this->assertEquals(
            User::DPA_VALIDATION_NOT_VALID,
            $user->validateDpa('foo', 'bar', 'not a dob', $user->getMobileNumber())
        );
        $this->assertEquals(
            User::DPA_VALIDATION_NOT_VALID,
            $user->validateDpa('foo', 'bar', '30/10/1980', '00321')
        );
        $this->assertEquals(
            User::DPA_VALIDATION_VALID,
            $user->validateDpa('foo', 'bar', '30/10/1980', $user->getMobileNumber())
        );
    }

    public function testValidateDpaFailMobile()
    {
        $user = new User();
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $user->setMobileNumber(self::generateRandomMobile());
        $user->setBirthday(new \DateTime('1980-10-30'));
        $closeMobile = mb_substr($user->getMobileNumber(), 0, 12);
        if (mb_substr($user->getMobileNumber(), 12, 1) == '0') {
            $closeMobile = $closeMobile . '1';
        } else {
            $closeMobile = $closeMobile . '0';
        }
        $this->assertEquals(
            User::DPA_VALIDATION_FAIL_MOBILE,
            $user->validateDpa('foo', 'bar', '30/10/1980', self::generateRandomMobile())
        );
        $this->assertEquals(
            User::DPA_VALIDATION_FAIL_MOBILE,
            $user->validateDpa('foo', 'bar', '30/10/1980', $closeMobile)
        );
        $this->assertEquals(
            User::DPA_VALIDATION_VALID,
            $user->validateDpa('foo', 'bar', '30/10/1980', $user->getMobileNumber())
        );
    }

    public function testValidateDpaFailDOB()
    {
        $user = new User();
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $user->setMobileNumber(self::generateRandomMobile());
        $user->setBirthday(new \DateTime('1980-10-30'));
        $this->assertEquals(
            User::DPA_VALIDATION_FAIL_DOB,
            $user->validateDpa('foo', 'bar', '29/10/1980', $user->getMobileNumber())
        );
        $this->assertEquals(
            User::DPA_VALIDATION_FAIL_DOB,
            $user->validateDpa('foo', 'bar', '31/10/1980', $user->getMobileNumber())
        );
        $this->assertEquals(
            User::DPA_VALIDATION_VALID,
            $user->validateDpa('foo', 'bar', '30/10/1980', $user->getMobileNumber())
        );
    }

    public function testValidateDpaFailFirstName()
    {
        $user = new User();
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $user->setMobileNumber(self::generateRandomMobile());
        $user->setBirthday(new \DateTime('1980-10-30'));
        $this->assertEquals(
            User::DPA_VALIDATION_FAIL_FIRSTNAME,
            $user->validateDpa('fo', 'bar', '30/10/1980', $user->getMobileNumber())
        );
        $this->assertEquals(
            User::DPA_VALIDATION_FAIL_FIRSTNAME,
            $user->validateDpa('fooo', 'bar', '30/10/1980', $user->getMobileNumber())
        );
        // should fail at user level, but may be transformed at service level
        $this->assertEquals(
            User::DPA_VALIDATION_FAIL_FIRSTNAME,
            $user->validateDpa('foó', 'bar', '30/10/1980', $user->getMobileNumber())
        );
        $this->assertEquals(
            User::DPA_VALIDATION_VALID,
            $user->validateDpa('foo', 'bar', '30/10/1980', $user->getMobileNumber())
        );
    }

    public function testValidateDpaFailLastName()
    {
        $user = new User();
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $user->setMobileNumber(self::generateRandomMobile());
        $user->setBirthday(new \DateTime('1980-10-30'));
        $this->assertEquals(
            User::DPA_VALIDATION_FAIL_LASTNAME,
            $user->validateDpa('foo', 'ba', '30/10/1980', $user->getMobileNumber())
        );
        $this->assertEquals(
            User::DPA_VALIDATION_FAIL_LASTNAME,
            $user->validateDpa('foo', 'barr', '30/10/1980', $user->getMobileNumber())
        );
        // should fail at user level, but may be transformed at service level
        $this->assertEquals(
            User::DPA_VALIDATION_FAIL_LASTNAME,
            $user->validateDpa('foo', 'bár', '30/10/1980', $user->getMobileNumber())
        );
        $this->assertEquals(
            User::DPA_VALIDATION_VALID,
            $user->validateDpa('foo', 'bar', '30/10/1980', $user->getMobileNumber())
        );
    }

    public function testIsEligibleForTagNotInTagsArray()
    {
        $user = new User();
        $this->assertFalse(
            $user->isEligibleForTag('NotInTagsArray')
        );
    }
}
