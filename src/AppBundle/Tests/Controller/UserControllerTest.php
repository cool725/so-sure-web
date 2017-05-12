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

/**
 * @group functional-net
 */
class UserControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

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
        $this->validateBonus($crawler, 304, 304);
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
        $claimService = self::$container->get('app.claims');
        $claimService->addClaim($policy, $claim);

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
        $form = $crawler->selectButton('Accept')->form();
        $crawler = self::$client->submit($form);

        $crawler = self::$client->request('GET', '/user/');
        $this->validateRewardPot($crawler, 10);
    }

    private function validateInviteAllowed($crawler, $allowed)
    {
        $count = 0;
        if ($allowed) {
            $count = 1;
        }
        $this->assertEquals($count, $crawler->evaluate('count(//a[@id="connect-with"])')[0]);
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
        $this->assertEquals(
            $daysRemaining,
            $crawler->filterXPath('//div[@id="connection-bonus-chart"]')->attr('data-bonus-days-remaining')
        );
        $this->assertEquals(
            $daysTotal,
            $crawler->filterXPath('//div[@id="connection-bonus-chart"]')->attr('data-bonus-days-total')
        );
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
}
