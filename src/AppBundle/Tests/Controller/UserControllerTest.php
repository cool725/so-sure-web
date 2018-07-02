<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\UserControllerTest
 */
class UserControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    public function setUp()
    {
        parent::setUp();
        self::$redis->flushdb();
    }

    public function tearDown()
    {
    }

    public function testUserOk()
    {
        $email = self::generateEmail('testUserOk', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(500);

        $crawler = self::$client->request('GET', '/user/');

        $this->validateBonus($crawler, 14, 14);
        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, true);
    }

    public function testUserOk2ndCliff()
    {
        $email = self::generateEmail('testUserOk2ndCliff', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $cliffDate = new \DateTime();
        $cliffDate = $cliffDate->sub(new \DateInterval('P14D'));
        $cliffDate = $cliffDate->sub(new \DateInterval('PT2S'));
        $policy = self::initPolicy($user, self::$dm, $phone, $cliffDate, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        // print_r($policy->getCurrentConnectionValues());
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/');

        $this->validateBonus($crawler, 46, 46);
        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, true);
    }

    public function testUserOkFinal()
    {
        $email = self::generateEmail('testUserOkFinal', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $cliffDate = new \DateTime();
        $cliffDate = $cliffDate->sub(new \DateInterval('P60D'));
        $cliffDate = $cliffDate->sub(new \DateInterval('PT1H'));
        $policy = self::initPolicy($user, self::$dm, $phone, $cliffDate, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        // print_r($policy->getCurrentConnectionValues());
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/');

        // todo - will fail during leap year
        $this->validateBonus($crawler, [304, 305], [304, 305]);
        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, true);
    }

    public function testUserClaimed()
    {
        $email = self::generateEmail('testUserClaimed', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $cliffDate = new \DateTime();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        // print_r($policy->getCurrentConnectionValues());
        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setNumber(rand(1, 999999));
        $claimsService = self::$container->get('app.claims');
        $claimsService->addClaim($policy, $claim);

        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/');

        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, false);
    }

    public function testUserInvite()
    {
        $email = self::generateEmail('testUserInvite-inviter', $this);
        $inviteeEmail = self::generateEmail('testUserInvite-invitee', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $invitee = self::createUser(
            self::$userManager,
            $inviteeEmail,
            $password,
            $phone,
            self::$dm
        );
        $inviteePolicy = self::initPolicy($invitee, self::$dm, $phone, null, true, true);
        $inviteePolicy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->assertTrue($inviteePolicy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/');
        self::verifyResponse(200);
        $form = $crawler->selectButton('email[submit]')->form();
        $form['email[email]'] = $inviteeEmail;
        $crawler = self::$client->submit($form);

        $this->login($inviteeEmail, $password, 'user/');
        $crawler = self::$client->request('GET', '/user/');
        $form = $crawler->selectButton('Accept')->form();
        $crawler = self::$client->submit($form);

        $crawler = self::$client->request('GET', '/user/');
        $this->validateRewardPot($crawler, 10);
    }

    public function testUserInviteOptOut()
    {
        $email = self::generateEmail('testUserInviteOptOut', $this);
        $inviteeEmail = 'foo@so-sure.com';
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $invitee = self::createUser(
            self::$userManager,
            $inviteeEmail,
            $password,
            $phone,
            self::$dm
        );
        $inviteePolicy = self::initPolicy($invitee, self::$dm, $phone, null, true, true);
        $inviteePolicy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->assertTrue($inviteePolicy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/');
        self::verifyResponse(200);
        $form = $crawler->selectButton('email[submit]')->form();
        $form['email[email]'] = $inviteeEmail;
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);
    }

    public function testUserSCode()
    {
        $email = self::generateEmail('testUserSCode-inviter', $this);
        $inviteeEmail = self::generateEmail('testUserSCode-invitee', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $invitee = self::createUser(
            self::$userManager,
            $inviteeEmail,
            $password,
            $phone,
            self::$dm
        );
        $inviteePolicy = self::initPolicy($invitee, self::$dm, $phone, null, true, true);
        $inviteePolicy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->assertTrue($inviteePolicy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user/');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', sprintf('/scode/%s', $inviteePolicy->getStandardSCode()->getCode()));
        self::verifyResponse(200);
        self::$client->followRedirects(false);

        $form = $crawler->selectButton('scode[submit]')->form();
        $this->assertEquals($inviteePolicy->getStandardSCode()->getCode(), $form['scode[scode]']->getValue());
        $crawler = self::$client->submit($form);

        $this->login($inviteeEmail, $password, 'user/');
        $crawler = self::$client->request('GET', '/user/');
        // print $crawler->html();
        $form = $crawler->selectButton('Accept')->form();
        $crawler = self::$client->submit($form);

        $crawler = self::$client->request('GET', '/user/');
        $this->validateRewardPot($crawler, 10);
    }

    public function testUserChangeEmailDuplicate()
    {
        $email = self::generateEmail('testUserChangeEmailDuplicate', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $dupUser = self::createUser(
            self::$userManager,
            self::generateEmail('testUserChangeEmailDuplicate-dup', $this),
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user/');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/contact-details');
        self::verifyResponse(200);

        $form = $crawler->selectButton('user_email_form[update]')->form();
        $form['user_email_form[email]'] = $dupUser->getEmail();
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->expectFlashError($crawler, 'email already exists in our system');
    }

    public function testUserChangeEmailActual()
    {
        $email = self::generateEmail('testUserChangeEmailActual', $this);
        $password = 'fooBar123!';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        self::$client = self::createClient();

        $this->login($email, $password, 'user/');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/contact-details');
        self::verifyResponse(200, null, null, '/user/contact-details');

        $form = $crawler->selectButton('user_email_form[update]')->form();
        $form['user_email_form[email]'] = self::generateEmail('testUserChangeEmailActual-new', $this);
        $crawler = self::$client->submit($form);
        self::verifyResponse(200, null, null, 'Submit email update./');
        $this->expectFlashSuccess($crawler, 'email address is updated');
    }

    public function testUserChangeEmailInvalidEmail()
    {
        $email = self::generateEmail('testUserChangeEmailInvalidEmail', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user/');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/contact-details');
        self::verifyResponse(200);

        $form = $crawler->selectButton('user_email_form[update]')->form();
        $form['user_email_form[email]'] = 'testUserChangeEmailInvalidEmail-dup@foo';
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        //print_r($crawler->html());
    }

    private function validateInviteAllowed($crawler, $allowed)
    {
        $count = 0;
        if ($allowed) {
            $count = 1;
        }
        $this->assertEquals($count, $crawler->evaluate('count(//a[@id="add-connect"])')[0]);
    }

    private function validateRewardPot($crawler, $amount)
    {
        $this->assertEquals(
            $amount,
            $crawler->filterXPath('//div[@id="reward-pot-chart"]')->attr('data-pot-value')
        );
    }

    private function validateBonus($crawler, $daysRemaining, $daysTotal)
    {
        $chart = $crawler->filterXPath('//div[@id="connection-bonus-chart"]');
        $actualRemaining = $chart->attr('data-bonus-days-remaining');
        $actualTotal = $chart->attr('data-bonus-days-total');
        if (is_array($daysRemaining)) {
            $this->assertTrue(in_array($actualRemaining, $daysRemaining));
        } else {
            $this->assertEquals($daysRemaining, $actualRemaining);
        }
        if (is_array($daysTotal)) {
            $this->assertTrue(in_array($actualTotal, $daysTotal));
        } else {
            $this->assertEquals($daysTotal, $actualTotal);
        }
    }

    private function validateRenewalAllowed($crawler, $allowed)
    {
        $count = 0;
        if ($allowed) {
            $count = 1;
        }
        $this->assertEquals($count, $crawler->evaluate('count(//li[@id="user-homepage--nav-renew"])')[0]);
    }

    public function testUserUnpaidPolicy()
    {
        $email = self::generateEmail('testUserUnpaid', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/unpaid');

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(500);
    }

    public function testUserUnpaidPolicyPaymentDetails()
    {
        $this->logout();
        $email = self::generateEmail('testUserUnpaidPolicyPaymentDetails', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/unpaid', '/user/payment-details');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/payment-details');
        self::$client->followRedirects(false);
        $this->assertEquals(
            sprintf('http://localhost/user/unpaid'),
            self::$client->getHistory()->current()->getUri()
        );
    }

    public function testUserInvalidPolicy()
    {
        $email = self::generateEmail('testUserInvalid', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::$dm->flush();
        $this->login($email, $password, 'user/invalid');

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(200);
    }

    public function testUserPolicyCancelledAndPaymentOwed()
    {
        $email = self::generateEmail('testUserPolicyCancelledAndPaymentOwed', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setCancelledReason(Policy::CANCELLED_UNPAID);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->isCancelledAndPaymentOwed());
        $this->login($email, $password, sprintf('purchase/remainder/%s', $policy->getId()));
    }

    public function testUserAccessDenied()
    {
        $emailA = self::generateEmail('testUserAccessDenied-A', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userA = self::createUser(
            self::$userManager,
            $emailA,
            $password,
            $phone,
            self::$dm
        );
        $policyA = self::initPolicy($userA, self::$dm, $phone, null, true, true);
        $policyA->setStatus(Policy::STATUS_ACTIVE);

        $emailB = self::generateEmail('testUserAccessDenied-B', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userB = self::createUser(
            self::$userManager,
            $emailB,
            $password,
            $phone,
            self::$dm
        );
        $policyB = self::initPolicy($userB, self::$dm, $phone, null, true, true);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policyA->getUser()->hasActivePolicy());
        $this->assertTrue($policyB->getUser()->hasActivePolicy());
        $this->login($emailA, $password, 'user/');

        $crawler = self::$client->request('GET', sprintf('/user/%s', $policyA->getId()));
        self::verifyResponse(200);

        $crawler = self::$client->request('GET', sprintf('/user/%s', $policyB->getId()));
        self::verifyResponse(403);
    }

    public function testUserRenewSimple()
    {
        $email = self::generateEmail('testUserRenewSimple', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $date = new \DateTime();
        $date = $date->sub(new \DateInterval('P350D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $date, true, true, false);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policy->isRenewed());

        $tomorrow = new \DateTime();
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicy = $this->getRenewalPolicy($policy, false, $tomorrow);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();

        $crawler = $this->login($email, $password, 'user/');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', '/user/renew');

        $form = $crawler->selectButton('renew_form[renew]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertEquals(Policy::STATUS_RENEWAL, $updatedRenewalPolicy->getStatus());
    }

    public function testUserRenewCustomMonthly()
    {
        $email = self::generateEmail('testUserRenewCustomMonthly', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $date = new \DateTime();
        $date = $date->sub(new \DateInterval('P350D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $date, true, true, false);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policy->isRenewed());

        $tomorrow = new \DateTime();
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicy = $this->getRenewalPolicy($policy, false, $tomorrow);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();

        $crawler = $this->login($email, $password, 'user/');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policy->getId()));

        $form = $crawler->selectButton('renew_form[renew]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertEquals(Policy::STATUS_RENEWAL, $updatedRenewalPolicy->getStatus());
    }

    public function testUserRenewCustomMonthlyDecline()
    {
        $email = self::generateEmail('testUserRenewCustomMonthlyDecline', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $date = new \DateTime();
        $date = $date->sub(new \DateInterval('P350D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $date, true, true, false);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policy->isRenewed());

        $tomorrow = new \DateTime();
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicy = $this->getRenewalPolicy($policy, false, $tomorrow);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();

        $crawler = $this->login($email, $password, 'user/');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policy->getId()));

        $form = $crawler->selectButton('decline_form[decline]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertEquals(Policy::STATUS_DECLINED_RENEWAL, $updatedRenewalPolicy->getStatus());
        $this->assertNull($updatedRenewalPolicy->getPreviousPolicy()->getCashback());
    }

    public function testUserRenewCashbackCustomMonthly()
    {
        $emailA = self::generateEmail('testUserRenewCashbackCustomMonthlyA', $this);
        $emailB = self::generateEmail('testUserRenewCashbackCustomMonthlyB', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userA = self::createUser(
            self::$userManager,
            $emailA,
            $password,
            $phone,
            self::$dm
        );
        $userB = self::createUser(
            self::$userManager,
            $emailB,
            $password,
            $phone,
            self::$dm
        );
        $date = new \DateTime();
        $date = $date->sub(new \DateInterval('P350D'));
        $policyA = self::initPolicy($userA, self::$dm, $phone, $date, true, true, false);
        $policyB = self::initPolicy($userB, self::$dm, $phone, $date, true, true, false);
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policyA->isRenewed());
        $this->assertFalse($policyB->isRenewed());
        
        self::connectPolicies(self::$invitationService, $policyA, $policyB, $date);

        $tomorrow = new \DateTime();
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicyA = $this->getRenewalPolicy($policyA, false, $tomorrow);
        $renewalPolicyB = $this->getRenewalPolicy($policyB, false, $tomorrow);
        static::$dm->persist($renewalPolicyA);
        static::$dm->persist($renewalPolicyB);
        static::$dm->flush();
        
        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $crawler = $this->login($emailA, $password, 'user/');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policyA->getId()));

        $form = $crawler->selectButton('renew_cashback_form[renew]')->form();
        $form['renew_cashback_form[accountName]'] = 'foo bar';
        $form['renew_cashback_form[sortCode]'] = '123456';
        $form['renew_cashback_form[accountNumber]'] = '12345678';
        $form['renew_cashback_form[encodedAmount]'] =
            sprintf('%0.2f|12|0', $renewalPolicyA->getPremium()->getYearlyPremiumPrice());
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $repo->find($renewalPolicyA->getId());
        $this->assertEquals(Policy::STATUS_RENEWAL, $updatedRenewalPolicyA->getStatus());
        $this->assertNotNull($updatedRenewalPolicyA->getPreviousPolicy()->getCashback());
    }

    public function testUserRenewCashbackCustomDeclined()
    {
        $emailA = self::generateEmail('testUserRenewCashbackCustomDeclinedA', $this);
        $emailB = self::generateEmail('testUserRenewCashbackCustomDeclinedB', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userA = self::createUser(
            self::$userManager,
            $emailA,
            $password,
            $phone,
            self::$dm
        );
        $userB = self::createUser(
            self::$userManager,
            $emailB,
            $password,
            $phone,
            self::$dm
        );
        $date = new \DateTime();
        $date = $date->sub(new \DateInterval('P350D'));
        $policyA = self::initPolicy($userA, self::$dm, $phone, $date, true, true, false);
        $policyB = self::initPolicy($userB, self::$dm, $phone, $date, true, true, false);
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policyA->isRenewed());
        $this->assertFalse($policyB->isRenewed());
        
        self::connectPolicies(self::$invitationService, $policyA, $policyB, $date);
        $tomorrow = new \DateTime();
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicyA = $this->getRenewalPolicy($policyA, false, $tomorrow);
        $renewalPolicyB = $this->getRenewalPolicy($policyB, false, $tomorrow);
        static::$dm->persist($renewalPolicyA);
        static::$dm->persist($renewalPolicyB);
        static::$dm->flush();
        
        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $crawler = $this->login($emailA, $password, 'user/');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policyA->getId()));

        $form = $crawler->selectButton('cashback_form[cashback]')->form();
        $form['cashback_form[accountName]'] = 'foo bar';
        $form['cashback_form[sortCode]'] = '123456';
        $form['cashback_form[accountNumber]'] = '12345678';
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $repo->find($renewalPolicyA->getId());
        $this->assertEquals(Policy::STATUS_DECLINED_RENEWAL, $updatedRenewalPolicyA->getStatus());
        $this->assertNotNull($updatedRenewalPolicyA->getPreviousPolicy()->getCashback());
    }

    public function testUserFormRateLimit()
    {
        $this->clearRateLimit();

        $email = self::generateEmail('testUserRateLimit', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $key = "PeerJUserSecurityBundle::login_failed::ip::127.0.0.1";
        $keyUsername = sprintf("PeerJUserSecurityBundle::%s::username::%s", 'login_failed', $email);

        $this->assertFalse(self::$redis->exists($key) == 1);
        $this->login($email, 'bar', 'login');
        $this->assertTrue(self::$redis->exists($key) == 1);

        $now = new \DateTime();
        $now = $now->sub(new \DateInterval(('PT1S')));
        for ($i = 1; $i <= 25; $i++) {
            self::$redis->zadd($key, [serialize(array($email, $now->getTimestamp())) => $now->getTimestamp()]);
            $now = $now->sub(new \DateInterval(('PT1S')));
        }

        //$this->login($email, 'bar', 'login', null, null);

        // expect a locked account
        $this->login($email, 'bar', 'login', null, 503);
    }

    public function testUserWelcomePage()
    {
        $email = self::generateEmail('testUserWelcomePage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        $welcomePage = sprintf('/user/welcome/%s', $policy->getId());
        // initial flag is false
        $this->login($email, $password);
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            "'has_visited_welcome_page': false",
            self::$client->getResponse()->getContent()
        );
        // set after first show to true
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            "'has_visited_welcome_page': true",
            self::$client->getResponse()->getContent()
        );
        // consistent after repeated show
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            "'has_visited_welcome_page': true",
            self::$client->getResponse()->getContent()
        );
    }

    public function testUserWelcomePageMultiPolicyShowLatest()
    {
        $email = self::generateEmail('testUserWelcomePageMultiPolicyShowLatest', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        // setting up 3 policies, middle onw being setuo is the latest by start date
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart(new \DateTime('2017-10-11'));
        self::$dm->flush();
        $policy2 = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setStart(new \DateTime('2018-1-11'));
        self::$dm->flush();
        $policy3 = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy3->setStatus(Policy::STATUS_ACTIVE);
        $policy3->setStart(new \DateTime('2016-1-11'));
        self::$dm->flush();

        // latest policy should be policy number 2
        $this->assertEquals($user->getLatestPolicy(), $policy2);


        //testing user welcome page without policy id
        $welcomePage = self::$router->generate('user_welcome');

        $this->login($email, $password);
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);

        //always expecting latest policy to be policy number2
        $this->assertContains(
            $user->getLatestPolicy()->getId(),
            self::$client->getResponse()->getContent()
        );
        // initial flag is false
        $this->assertContains(
            "'has_visited_welcome_page': false",
            self::$client->getResponse()->getContent()
        );

        // set after first show to true
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            $user->getLatestPolicy()->getId(),
            self::$client->getResponse()->getContent()
        );
        $this->assertContains(
            "'has_visited_welcome_page': true",
            self::$client->getResponse()->getContent()
        );

        // consistent after repeated show
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            $user->getLatestPolicy()->getId(),
            self::$client->getResponse()->getContent()
        );
        $this->assertContains(
            "'has_visited_welcome_page': true",
            self::$client->getResponse()->getContent()
        );

        // consistent after repeated show
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertNotContains(
            $user->getFirstPolicy()->getId(),
            self::$client->getResponse()->getContent()
        );
        $this->assertContains(
            "'has_visited_welcome_page': true",
            self::$client->getResponse()->getContent()
        );

    }

    public function testUserWelcomePageNotOwnedPolicy()
    {
        $email = self::generateEmail('testUserWelcomePageNotOwnedPolicy1', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $email2 = self::generateEmail('testUserWelcomePageNotOwnedPolicy2', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user2 = self::createUser(
            self::$userManager,
            $email2,
            $password,
            $phone,
            self::$dm
        );

        //visiting
        $policy = self::initPolicy($user2, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart(new \DateTime('2017-10-11'));
        self::$dm->flush();

        $policy2 = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setStart(new \DateTime('2017-10-11'));
        self::$dm->flush();

        $welcomePage = self::$router->generate('user_welcome_policy_id', ['id' => $policy->getId()]);
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $welcomePage);
        self::verifyResponse(403);
    }

    public function testUserWelcomePageInvalidPolicy()
    {
        $email = self::generateEmail('testUserWelcomePageInvalidPolicy', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart(new \DateTime('2017-10-11'));
        self::$dm->flush();

        $welcomePage = self::$router->generate('user_welcome_policy_id', ['id' => rand(0, 10000000)]);
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $welcomePage);
        self::verifyResponse(404);
    }

    public function testUserClaimWithNoActivePolicy()
    {
        $email = self::generateEmail('testUserClaimWithNoActivePolicy', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = new \DateTime();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setStart($now);
        self::$dm->flush();

        $claimPage = self::$router->generate('claim_policy');
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(404);
    }

    public function testUserClaimWithActivePolicyOpenedClaim()
    {
        $email = self::generateEmail('testUserClaimWithActivePolicyOpenedClaim', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = new \DateTime();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setStart($now);

        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $claim->setPolicy($policy);
        $policy->addClaim($claim);
        self::$dm->flush();

        $claimPage = self::$router->generate('claim_policy');
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(404);
    }

    public function testUserClaimFnol()
    {
        $email = self::generateEmail('testUserClaimFnol', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = new \DateTime();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);
        self::$dm->flush();

        $mobileNumber = self::generateRandomMobile();
        $serializer = new Serializer(array(new DateTimeNormalizer()));

        $claimPage = self::$router->generate('claim_policy');
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);
        $form = $crawler->selectButton('claim_form[submit]')->form();
        $form['claim_form[email]'] = $email;
        $form['claim_form[name]'] = 'foo bar';
        $form['claim_form[phone]'] = $mobileNumber;
        $form['claim_form[when]'] = $serializer->normalize(
            $now,
            null,
            array(DateTimeNormalizer::FORMAT_KEY => 'd/m/Y')
        );
        $form['claim_form[time]'] = '02:00';
        $form['claim_form[where]'] = 'so-sure offices';
        $form['claim_form[timeToReach]'] = '2 pm';
        $form['claim_form[signature]'] = 'foo bar';
        $form['claim_form[type]'] = Claim::TYPE_DAMAGE;
        $form['claim_form[network]'] = Claim::NETWORK_O2;
        // @codingStandardsIgnoreStart
        $form['claim_form[message]'] = 'bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla';
        $form['claim_form[policyNumber]'] = $policy->getId();
        $crawler = self::$client->submit($form);

        self::verifyResponse(200);
        $this->assertContains(
            'data-active="claimfnol-confirm"',
            self::$client->getResponse()->getContent()
        );

        $form = $crawler->selectButton('claim_confirm_form[submit]')->form();
        $form['claim_confirm_form[email]'] = $email;
        $form['claim_confirm_form[name]'] = 'foo bar';
        $form['claim_confirm_form[phone]'] = $mobileNumber;
        $form['claim_confirm_form[when]'] = $serializer->normalize(
            $now,
            null,
            array(DateTimeNormalizer::FORMAT_KEY => 'Y/m/d')
        );
        $form['claim_confirm_form[time]'] = '2 am';
        $form['claim_confirm_form[where]'] = 'so-sure offices';
        $form['claim_confirm_form[timeToReach]'] = '2 pm';
        $form['claim_confirm_form[signature]'] = 'foo bar';
        $form['claim_confirm_form[type]'] = Claim::TYPE_DAMAGE;
        $form['claim_confirm_form[network]'] = Claim::NETWORK_O2;
        // @codingStandardsIgnoreStart
        $form['claim_confirm_form[message]'] = 'bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla';
        $form['claim_confirm_form[policyNumber]'] = $policy->getId();
        $crawler = self::$client->submit($form);

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect(sprintf('/user/claim/%s', $policy->getId())));

        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policy->getId());
        $updatedClaim = $updatedPolicy->getLatestClaim();
        $this->assertEquals(Claim::STATUS_FNOL, $updatedClaim->getStatus());
    }

    public function testUserClaimDamage()
    {
        $email = self::generateEmail('testUserClaimDamage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = new \DateTime();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);

        $claim = new Claim();
        $claim->setIncidentDate($now);
        $claim->setIncidentTime('2 am');
        $claim->setLocation('so-sure offices');
        $claim->setTimeToReach('2 pm');
        $claim->setPhoneToReach( self::generateRandomMobile());
        $claim->setSignature('foo bar');
        $claim->setType(Claim::TYPE_DAMAGE);
        $claim->setNetwork(Claim::NETWORK_O2);
        $claim->setDescription('bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla');
        $claim->setStatus(Claim::STATUS_FNOL);
        $claim->setPolicy($policy);
        $policy->addClaim($claim);
        self::$dm->flush();

        $proofOfUsageFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/proofOfUsage.txt",
            self::$rootDir
        );
        $proofOfUsage = new UploadedFile(
            $proofOfUsageFile,
            'proofOfUsage.txt',
            'text/plain',
            14
        );

        $damagePictureFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/damagePicture.jpg",
            self::$rootDir
        );
        $damagePicture = new UploadedFile(
            $damagePictureFile,
            'damagePicture.jpg',
            'image/jpeg',
            1305630
        );

        $claimPage = self::$router->generate('claimed_damage_policy', ['policyId' => $policy->getId()]);
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);
        $form = $crawler->selectButton('claim_damage_form[confirm]')->form();
        $form['claim_damage_form[typeDetails]'] = Claim::DAMAGE_BROKEN_SCREEN;
        $form['claim_damage_form[typeDetailsOther]'] = '';
        $form['claim_damage_form[monthOfPurchase]'] = 'December';
        $form['claim_damage_form[yearOfPurchase]'] = '2018';
        $form['claim_damage_form[phoneStatus]'] = Claim::PHONE_STATUS_NEW;
        $form['claim_damage_form[proofOfUsage]']->upload($proofOfUsage);
        $form['claim_damage_form[pictureOfPhone]']->upload($damagePicture);
        $crawler = self::$client->submit($form);

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect(sprintf('/user/claim/%s', $policy->getId())));

        $claimPage = self::$router->generate('claimed_policy', ['policyId' => $policy->getId()]);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $this->assertContains(
            "Thank you for submitting your claim",
            self::$client->getResponse()->getContent()
        );

        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policy->getId());
        $updatedClaim = $updatedPolicy->getLatestClaim();
        $this->assertEquals(Claim::STATUS_SUBMITTED, $updatedClaim->getStatus());
    }

    public function testUserClaimLoss()
    {
        $email = self::generateEmail('testUserClaimLoss', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = new \DateTime();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);

        $claim = new Claim();
        $claim->setIncidentDate($now);
        $claim->setIncidentTime('2 am');
        $claim->setLocation('so-sure offices');
        $claim->setTimeToReach('2 pm');
        $claim->setPhoneToReach( self::generateRandomMobile());
        $claim->setSignature('foo bar');
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setNetwork(Claim::NETWORK_O2);
        $claim->setDescription('bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla');
        $claim->setStatus(Claim::STATUS_FNOL);
        $claim->setPolicy($policy);
        $policy->addClaim($claim);
        self::$dm->flush();

        $serializer = new Serializer(array(new DateTimeNormalizer()));

        $proofOfUsageFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/proofOfUsage.txt",
            self::$rootDir
        );
        $proofOfUsage = new UploadedFile(
            $proofOfUsageFile,
            'proofOfUsage.txt',
            'text/plain',
            14
        );

        $proofOfBarringFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/proofOfBarring.txt",
            self::$rootDir
        );
        $proofOfBarring = new UploadedFile(
            $proofOfBarringFile,
            'proofOfBarring.txt',
            'text/plain',
            16
         );

        $proofOfPurchaseFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/proofOfPurchase.txt",
            self::$rootDir
        );
        $proofOfPurchase = new UploadedFile(
            $proofOfPurchaseFile,
            'proofOfPurchase.txt',
            'text/plain',
            17
        );

        $claimPage = self::$router->generate('claimed_theftloss_policy', ['policyId' => $policy->getId()]);
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);
        $form = $crawler->selectButton('claim_theftloss_form[confirm]')->form();
        $form['claim_theftloss_form[hasContacted]'] = true;
        $form['claim_theftloss_form[contactedPlace]'] = 'so-sure offices';
        $form['claim_theftloss_form[blockedDate]'] = $serializer->normalize(
            $now,
            null,
            array(DateTimeNormalizer::FORMAT_KEY => 'd/m/Y')
        );
        $form['claim_theftloss_form[reportedDate]'] = $serializer->normalize(
            $now,
            null,
            array(DateTimeNormalizer::FORMAT_KEY => 'd/m/Y')
        );
        $form['claim_theftloss_form[reportType]'] = Claim::REPORT_POLICE_STATION;
        $form['claim_theftloss_form[policeLossReport]'] = '1234567890';
        $form['claim_theftloss_form[proofOfUsage]']->upload($proofOfUsage);
        $form['claim_theftloss_form[proofOfBarring]']->upload($proofOfBarring);
        $form['claim_theftloss_form[proofOfPurchase]']->upload($proofOfPurchase);
        $crawler = self::$client->submit($form);

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect(sprintf('/user/claim/%s', $policy->getId())));

        $claimPage = self::$router->generate('claimed_policy', ['policyId' => $policy->getId()]);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $this->assertContains(
            "Thank you for submitting your claim",
            self::$client->getResponse()->getContent()
        );

        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policy->getId());
        $updatedClaim = $updatedPolicy->getLatestClaim();
        $this->assertEquals(Claim::STATUS_SUBMITTED, $updatedClaim->getStatus());
    }

    public function testUserClaimTheft()
    {
        $email = self::generateEmail('testUserClaimTheft', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = new \DateTime();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);

        $claim = new Claim();
        $claim->setIncidentDate($now);
        $claim->setIncidentTime('2 am');
        $claim->setLocation('so-sure offices');
        $claim->setTimeToReach('2 pm');
        $claim->setPhoneToReach( self::generateRandomMobile());
        $claim->setSignature('foo bar');
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setNetwork(Claim::NETWORK_O2);
        $claim->setDescription('bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla');
        $claim->setStatus(Claim::STATUS_FNOL);
        $claim->setPolicy($policy);
        $policy->addClaim($claim);
        self::$dm->flush();

        $serializer = new Serializer(array(new DateTimeNormalizer()));

        $proofOfUsageFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/proofOfUsage.txt",
            self::$rootDir
        );
        $proofOfUsage = new UploadedFile(
            $proofOfUsageFile,
            'proofOfUsage.txt',
            'text/plain',
            14
        );

        $proofOfBarringFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/proofOfBarring.txt",
            self::$rootDir
        );
        $proofOfBarring = new UploadedFile(
            $proofOfBarringFile,
            'proofOfBarring.txt',
            'text/plain',
            16
         );

        $proofOfPurchaseFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/proofOfPurchase.txt",
            self::$rootDir
        );
        $proofOfPurchase = new UploadedFile(
            $proofOfPurchaseFile,
            'proofOfPurchase.txt',
            'text/plain',
            17
        );

        $claimPage = self::$router->generate('claimed_theftloss_policy', ['policyId' => $policy->getId()]);
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);
        $form = $crawler->selectButton('claim_theftloss_form[confirm]')->form();
        $form['claim_theftloss_form[hasContacted]'] = true;
        $form['claim_theftloss_form[contactedPlace]'] = 'so-sure offices';
        $form['claim_theftloss_form[blockedDate]'] = $serializer->normalize(
            $now,
            null,
            array(DateTimeNormalizer::FORMAT_KEY => 'd/m/Y')
        );
        $form['claim_theftloss_form[reportedDate]'] = $serializer->normalize(
            $now,
            null,
            array(DateTimeNormalizer::FORMAT_KEY => 'd/m/Y')
        );
        $form['claim_theftloss_form[reportType]'] = Claim::REPORT_POLICE_STATION;
        $form['claim_theftloss_form[crimeReferenceNumber]'] = '1234567890';
        $form['claim_theftloss_form[proofOfUsage]']->upload($proofOfUsage);
        $form['claim_theftloss_form[proofOfBarring]']->upload($proofOfBarring);
        $form['claim_theftloss_form[proofOfPurchase]']->upload($proofOfPurchase);
        $crawler = self::$client->submit($form);

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect(sprintf('/user/claim/%s', $policy->getId())));

        $claimPage = self::$router->generate('claimed_policy', ['policyId' => $policy->getId()]);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $this->assertContains(
            "Thank you for submitting your claim",
            self::$client->getResponse()->getContent()
        );

        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policy->getId());
        $updatedClaim = $updatedPolicy->getLatestClaim();
        $this->assertEquals(Claim::STATUS_SUBMITTED, $updatedClaim->getStatus());
    }

    public function testUserClaimUpdate()
    {
        $email = self::generateEmail('testUserClaimUpdate', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = new \DateTime('2018-01-01');
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);

        $claim = new Claim();
        $claim->setIncidentDate($now);
        $claim->setIncidentTime('2 am');
        $claim->setLocation('so-sure offices');
        $claim->setTimeToReach('2 pm');
        $claim->setPhoneToReach( self::generateRandomMobile());
        $claim->setSignature('foo bar');
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setNetwork(Claim::NETWORK_O2);
        $claim->setDescription('bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla');
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $claim->setPolicy($policy);
        $policy->addClaim($claim);
        self::$dm->flush();

        $serializer = new Serializer(array(new DateTimeNormalizer()));

        $proofOfUsageFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/proofOfUsage.txt",
            self::$rootDir
        );
        $proofOfUsage = new UploadedFile(
            $proofOfUsageFile,
            'proofOfUsage.txt',
            'text/plain',
            14
        );

        $claimPage = self::$router->generate('claimed_policy', ['policyId' => $policy->getId()]);
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $form = $crawler->selectButton('claim_update_form[confirm]')->form();
        $form['claim_update_form[when]'] = $serializer->normalize(
            $now,
            null,
            array(DateTimeNormalizer::FORMAT_KEY => 'd/m/Y')
        );
        $form['claim_update_form[time]'] = '08:00';
        $form['claim_update_form[proofOfUsage]']->upload($proofOfUsage);
        $crawler = self::$client->submit($form);

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect(sprintf('/user/claim/%s', $policy->getId())));

        $claimPage = self::$router->generate('claimed_policy', ['policyId' => $policy->getId()]);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $this->assertContains(
            "Thank you for submitting your claim",
            self::$client->getResponse()->getContent()
        );

        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policy->getId());
        $updatedClaim = $updatedPolicy->getLatestClaim();
        $this->assertEquals(Claim::STATUS_SUBMITTED, $updatedClaim->getStatus());
        $this->assertEquals($now, $updatedClaim->getIncidentDate());
        $this->assertEquals('08:00', $updatedClaim->getIncidentTime());
    }

    public function testUserClaimWithdraw()
    {
        $email = self::generateEmail('testUserClaimWithdraw', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = new \DateTime();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);

        $claim = new Claim();
        $claim->setIncidentDate($now);
        $claim->setIncidentTime('2 am');
        $claim->setLocation('so-sure offices');
        $claim->setTimeToReach('2 pm');
        $claim->setPhoneToReach( self::generateRandomMobile());
        $claim->setSignature('foo bar');
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setNetwork(Claim::NETWORK_O2);
        $claim->setDescription('bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla');
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $claim->setPolicy($policy);
        $policy->addClaim($claim);
        self::$dm->flush();

        $claimPage = self::$router->generate('withdraw_claimed_policy', ['policyId' => $policy->getId()]);
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(302);

        $userPage = self::$router->generate('user_home');
        $crawler = self::$client->request('GET', $userPage);

        $this->expectFlashSuccess($crawler, 'Your claim has been withdrawn.');

        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policy->getId());
        $updatedClaim = $updatedPolicy->getLatestClaim();
        $this->assertEquals(Claim::STATUS_WITHDRAWN, $updatedClaim->getStatus());
    }
}
