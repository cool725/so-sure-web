<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\PhonePolicy;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use AppBundle\DataFixtures\MongoDB\b\User\LoadUserData;

/**
 * @group functional-net
 * AppBundle\\Tests\\Controller\\ClaimsControllerTest
 */
class ClaimsControllerTest extends BaseControllerTest
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

    public function testClaimsLoginViewPartialPolicy()
    {
        $email = self::generateEmail('testClaimsLoginViewPolicyPending', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::addAddress($user);
        self::$dm->flush();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        self::$dm->flush();

        $this->login(LoadUserData::DEFAULT_CLAIMS_DIRECTGROUP, LoadUserData::DEFAULT_PASSWORD, 'claims/policies');

        $crawler = self::$client->request('GET', sprintf('/claims/policy/%s', $policy->getId()));
        $this->verifyResponse(200);
    }

    public function testClaimsLoginViewPolicyActive()
    {
        $email = self::generateEmail('testClaimsLoginViewPolicyActive', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::addAddress($user);
        self::$dm->flush();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $this->login(LoadUserData::DEFAULT_CLAIMS_DIRECTGROUP, LoadUserData::DEFAULT_PASSWORD, 'claims/policies');

        $crawler = self::$client->request('GET', sprintf('/claims/policy/%s', $policy->getId()));
        $this->verifyResponse(200);
    }

    public function testClaimsLossRejectedPicSure()
    {
        $email = self::generateEmail('testClaimsLossRejectedPicSure', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::addAddress($user);
        self::$dm->flush();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED);
        self::$dm->flush();
        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $this->login(LoadUserData::DEFAULT_CLAIMS_DIRECTGROUP, LoadUserData::DEFAULT_PASSWORD, 'claims/policies');

        $crawler = self::$client->request('GET', sprintf('/claims/policy/%s', $policy->getId()));
        $this->verifyResponse(200);
        $form = $crawler->selectButton('claim[record]')->form();
        $form['claim[number]']->setValue(self::getRandomClaimNumber());
        $form['claim[type]']->setValue('loss');
        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        $this->verifyResponse(200);
        self::$client->followRedirects(false);

        $this->expectFlashSuccess($crawler, 'Excess is £150');
        $claims = $crawler->filterXPath('//div[@id="claims"]');
        $this->assertContains('£150', $claims->text());
    }

    public function testClaimsDamageRejectedPicSure()
    {
        $email = self::generateEmail('testClaimsDamageRejectedPicSure', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::addAddress($user);
        self::$dm->flush();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED);
        self::$dm->flush();
        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $this->login(LoadUserData::DEFAULT_CLAIMS_DIRECTGROUP, LoadUserData::DEFAULT_PASSWORD, 'claims/policies');

        $crawler = self::$client->request('GET', sprintf('/claims/policy/%s', $policy->getId()));
        $form = $crawler->selectButton('claim[record]')->form();
        $form['claim[number]']->setValue(rand(1, 999999));
        $form['claim[type]']->setValue('damage');
        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        $this->verifyResponse(200);
        self::$client->followRedirects(false);
        $this->expectFlashSuccess($crawler, 'Excess is £150');
        $claims = $crawler->filterXPath('//div[@id="claims"]');
        $this->assertContains('£150', $claims->text());
    }

    public function testClaimsTheftApprovedPicSure()
    {
        $email = self::generateEmail('testClaimsTheftApprovedPicSure', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::addAddress($user);
        self::$dm->flush();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        self::$dm->flush();
        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $this->login(LoadUserData::DEFAULT_CLAIMS_DIRECTGROUP, LoadUserData::DEFAULT_PASSWORD, 'claims/policies');

        $crawler = self::$client->request('GET', sprintf('/claims/policy/%s', $policy->getId()));
        $form = $crawler->selectButton('claim[record]')->form();
        $form['claim[number]']->setValue(rand(1, 999999));
        $form['claim[type]']->setValue('theft');
        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        $this->verifyResponse(200);
        self::$client->followRedirects(false);
        $this->expectFlashSuccess($crawler, 'Excess is £70');
        $claims = $crawler->filterXPath('//div[@id="claims"]');
        $this->assertContains('£70', $claims->text());
    }

    public function testClaimsDamageApprovedPicSure()
    {
        $email = self::generateEmail('testClaimsDamageApprovedPicSure', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::addAddress($user);
        self::$dm->flush();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        self::$dm->flush();
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->assertNotNull($policy->getCurrentExcess());

        $this->login(LoadUserData::DEFAULT_CLAIMS_DIRECTGROUP, LoadUserData::DEFAULT_PASSWORD, 'claims/policies');

        $crawler = self::$client->request('GET', sprintf('/claims/policy/%s', $policy->getId()));
        $form = $crawler->selectButton('claim[record]')->form();
        $form['claim[number]']->setValue(rand(1, 999999));
        $form['claim[type]']->setValue('damage');
        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        $this->verifyResponse(200);
        self::$client->followRedirects(false);
        $this->expectFlashSuccess($crawler, 'Excess is £50');
        $claims = $crawler->filterXPath('//div[@id="claims"]');
        $this->assertContains('£50', $claims->text());
    }
}
