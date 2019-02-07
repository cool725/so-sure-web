<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Opt\EmailOptIn;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\PhonePrice;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use Symfony\Bundle\SwiftmailerBundle\DataCollector\MessageDataCollector;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * @group functional-net
 */
class DefaultControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function tearDown()
    {
    }

    public function testIndex()
    {
        $crawler = self::$client->request('GET', '/');
        self::verifyResponse(200);
    }

    public function testTagManager()
    {
        $crawler = self::$client->request('GET', '/');
        self::verifyResponse(200);
        $tag = $this->getContainer(true)->getParameter('ga_tag_manager_env');
        $body = $this->getClientResponseContent();

        // Not a perfect test, but unable to test js code via symfony client
        // This should at least detect if the custom tag manager code environment was accidental removed
        $this->assertTrue(mb_stripos($body, $tag) !== false);
    }

    public function testIndexRedirect()
    {
        // usa ip should no longer redirect
        $crawler = self::$client->request('GET', '/', [], [], ['REMOTE_ADDR' => '70.248.28.23']);
        self::verifyResponse(200);
    }

    public function testQuotePhoneRouteMakeModelMemory()
    {
        $url = self::$router->generate('quote_make_model_memory', [
            'make' => 'apple',
            'model' => 'iphone+6s',
            'memory' => 64,
        ]);

        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
    }

    public function testQuotePhoneSpaceRouteMakeModelMemory()
    {
        $url = self::$router->generate('quote_make_model_memory', [
            'make' => 'apple',
            'model' => 'iphone 6s',
            'memory' => 64,
        ]);
        $redirectUrl = self::$router->generate('quote_make_model_memory', [
            'make' => 'apple',
            'model' => 'iphone+6s',
            'memory' => 64,
        ]);

        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(301);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl), json_encode($crawler->html()));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testQuotePhoneRouteMakeModel()
    {
        $crawler = self::$client->request('GET', self::$router->generate('quote_make_model', [
            'make' => 'apple',
            'model' => 'iphone+6s',
        ]));
        self::verifyResponse(200);
    }

    public function testQuotePhoneSpaceRouteMakeModel()
    {
        $url = self::$router->generate('quote_make_model', [
            'make' => 'apple',
            'model' => 'iphone 6s',
        ]);
        $redirectUrl = self::$router->generate('quote_make_model', [
            'make' => 'apple',
            'model' => 'iphone+6s',
        ]);

        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(301);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl), $crawler->html());
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testQuotePhone()
    {
        $repo = self::$dm->getRepository(Phone::class);
        /** @var Phone $phone */
        $phone = $repo->findOneBy(['devices' => 'iPhone 8', 'memory' => 64]);

        $this->setPhoneSession($phone);

        /** @var PhonePrice $price */
        $price = $phone->getCurrentPhonePrice();
        $this->assertContains(
            sprintf("&pound;%.2f", $price->getMonthlyPremiumPrice()),
            $this->getClientResponseContent()
        );
    }

    public function testTextAppInvalidMobile()
    {
        $crawler = self::$client->request('GET', self::$router->generate('sms_app_link'));
        self::verifyResponse(200);

        $form = $crawler->selectButton('Text me a link')->form();
        $form['sms_app_link[mobileNumber]'] = '123';
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->assertContains(
            "valid UK Mobile Number",
            $this->getClientResponseContent()
        );
    }

    public function testTextAppLeadPresent()
    {
        $lead = new Lead();
        $lead->setMobileNumber(static::generateRandomMobile());
        self::$dm->persist($lead);
        self::$dm->flush();

        $crawler = self::$client->request('GET', self::$router->generate('sms_app_link'));
        self::verifyResponse(200);

        $form = $crawler->selectButton('Text me a link')->form();
        $form['sms_app_link[mobileNumber]'] = str_replace('+44', '', $lead->getMobileNumber());
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->assertContains(
            "already sent you a link",
            $this->getClientResponseContent()
        );
    }

    public function testPhoneSearchHomepage()
    {
        $crawler = self::$client->request('GET', '/');
        $this->assertEquals(200, $this->getClientResponseStatusCode());
        $this->assertHasFormAction($crawler, '/select-phone-dropdown');
    }

    public function areLinksValid($name, $key, $allKeys, $phoneLinks)
    {
        //each page contains link to different memory models of the current phone
        $arrayLinks = array_values($phoneLinks);
        unset($allKeys[array_search($key, $allKeys)]);
        foreach ($allKeys as $memory) {
            //check if valid link to each memory model is present
            $expected_url = sprintf(
                PurchaseControllerTest::SEARCH_URL2_TEMPLATE,
                $name,
                $memory
            );
            $this->assertTrue(in_array($expected_url, $arrayLinks));
        }
    }

    public function testOptOutEmail()
    {
        $email1 = self::generateEmail('testOptOutEmail-1', $this);
        $email2 = self::generateEmail('testOptOutEmail-2', $this);

        $crawler = self::$client->request('GET', '/communications');
        $form = $crawler->selectButton('form[decline]')->form();
        $form['form[email]'] = $email1;
        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        self::$client->followRedirects(false);
        $this->expectFlashSuccess($crawler, 'receive an email shortly');

        self::$client->enableProfiler();
        if (!self::$client->getProfile()) {
            throw new \Exception('Profiler must be enabled');
        }
        $crawler = self::$client->request('GET', '/communications');
        $form = $crawler->selectButton('form[decline]')->form();
        $form['form[email]'] = $email2;
        $crawler = self::$client->submit($form);
        $this->assertNotNull(self::$client->getProfile());
        if (self::$client->getProfile()) {
            /** @var MessageDataCollector $mailCollector */
            $mailCollector = self::$client->getProfile()->getCollector('swiftmailer');
            $collectedMessages = $mailCollector->getMessages();
            $this->assertCount(1, $collectedMessages);
            $collectedMessages = $mailCollector->getMessages();
            $this->assertCount(1, $collectedMessages);
            $this->assertContains('manage your communication preferences', $collectedMessages[0]->getBody());
        }
    }

    public function testOptInEmailHash()
    {
        $email = self::generateEmail('testOptInEmailHash', $this);

        $url = sprintf('/communications/%s', SoSure::encodeCommunicationsHash($email));
        $crawler = self::$client->request('GET', $url);
        $form = $crawler->selectButton('optin_form[update]')->form();
        $form['optin_form[categories]'][0]->tick(); // = EmailOptIn::OPTIN_CAT_MARKETING;
        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        self::$client->followRedirects(false);
        $this->expectFlashSuccess($crawler, 'Your preferences have been updated');

        $repo = self::$dm->getRepository(EmailOptIn::class);
        /** @var EmailOptIn $optin */
        $optin = $repo->findOneBy(['email' => mb_strtolower($email)]);
        $this->assertNotNull($optin);
        $this->assertContains(EmailOptIn::OPTIN_CAT_MARKETING, $optin->getCategories());
    }

    public function testOptOutEmailHash()
    {
        $email = self::generateEmail('testOptOutEmailHash', $this);

        $url = sprintf('/communications/%s', SoSure::encodeCommunicationsHash($email));
        $crawler = self::$client->request('GET', $url);
        $form = $crawler->selectButton('optout_form[update]')->form();
        $form['optout_form[categories]'][0]->tick();
        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        self::$client->followRedirects(false);
        $this->expectFlashSuccess($crawler, 'Your preferences have been updated');

        $repo = self::$dm->getRepository(EmailOptOut::class);
        /** @var EmailOptOut $optout */
        $optout = $repo->findOneBy(['email' => mb_strtolower($email)]);
        $this->assertNotNull($optout);
        $this->assertContains(EmailOptOut::OPTOUT_CAT_INVITATIONS, $optout->getCategories());
    }

    public function testClaimAlreadyLogin()
    {
        $email = self::generateEmail('testClaimAlreadyLogin', $this, true);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $policy = self::initPolicy(
            $user,
            self::$dm,
            $phone,
            null,
            true,
            true
        );

        $policy->setStatus(Policy::STATUS_ACTIVE);

        self::$dm->persist($policy);
        self::$dm->flush();

        $claimPage = self::$router->generate('claim');
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(sprintf('/user/claim')));
    }

    public function testClaimAlreadyLoginAdmin()
    {
        $email = self::generateEmail('testClaimAlreadyLoginAdmin', $this, true);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $user->addRole(User::ROLE_ADMIN);

        $policy = self::initPolicy(
            $user,
            self::$dm,
            $phone,
            null,
            true,
            true
        );

        $policy->setStatus(Policy::STATUS_ACTIVE);

        self::$dm->persist($policy);
        self::$dm->flush();

        $claimPage = self::$router->generate('claim');
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);
    }

    public function testClaimAlreadyLoginClaims()
    {
        $email = self::generateEmail('testClaimAlreadyLoginClaims', $this, true);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $user->addRole(User::ROLE_CLAIMS);

        $policy = self::initPolicy(
            $user,
            self::$dm,
            $phone,
            null,
            true,
            true
        );

        $policy->setStatus(Policy::STATUS_ACTIVE);

        self::$dm->persist($policy);
        self::$dm->flush();

        $claimPage = self::$router->generate('claim');
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);
    }

    public function testClaimCancelledPolicy()
    {
        $email = self::generateEmail('testClaimCancelledPolicy', $this, true);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $policy = self::initPolicy(
            $user,
            self::$dm,
            $phone,
            null,
            true,
            true
        );

        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->cancel(Policy::CANCELLED_COOLOFF);

        self::$dm->persist($policy);
        self::$dm->flush();

        $claimPage = self::$router->generate('claim');
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $form = $crawler->selectButton('claim_email_form[submit]')->form();
        $form['claim_email_form[email]'] = $email;
        $crawler = self::$client->submit($form);

        self::verifyResponse(200);
        $this->expectFlashSuccess($crawler, 'email with further instructions');
    }

    public function testClaimUnpaidPolicy()
    {
        $email = self::generateEmail('testClaimUnpaidPolicy', $this, true);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $policy = self::initPolicy(
            $user,
            self::$dm,
            $phone,
            null,
            true,
            true
        );

        $policy->setStatus(Policy::STATUS_UNPAID);

        self::$dm->persist($policy);
        self::$dm->flush();

        $claimPage = self::$router->generate('claim');
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(sprintf('/user/claim')));
    }

    public function testClaimUserNotFound()
    {
        $this->logout();
        $claimPage = self::$router->generate('claim');
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);
        $form = $crawler->selectButton('claim_email_form[submit]')->form();
        $form['claim_email_form[email]'] = self::generateEmail('testClaimUserNotFoundRandom', $this);
        $crawler = self::$client->submit($form);

        self::verifyResponse(200);
        $this->expectFlashSuccess($crawler, 'email with further instructions');
    }

    public function testClaimUserNoActivePolicy()
    {
        $this->logout();
        $email = self::generateEmail('testClaimUserNoActivePolicy', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setStart($now);
        self::$dm->flush();

        $claimPage = self::$router->generate('claim');
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);
        $form = $crawler->selectButton('claim_email_form[submit]')->form();
        $form['claim_email_form[email]'] = $email;
        $crawler = self::$client->submit($form);

        self::verifyResponse(200);
        $this->expectFlashSuccess($crawler, 'email with further instructions');
    }

    public function testClaimValid()
    {
        $this->logout();
        $email = self::generateEmail('testClaimValid', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);
        self::$dm->flush();

        $claimPage = self::$router->generate('claim');
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);
        $form = $crawler->selectButton('claim_email_form[submit]')->form();
        $form['claim_email_form[email]'] = $email;
        $crawler = self::$client->submit($form);

        self::verifyResponse(200);
        $this->expectFlashSuccess($crawler, 'email with further instructions');
    }

    public function testClaimLoginAlreadyLogin()
    {
        $email = self::generateEmail('testClaimLoginAlreadyLogin', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $policy = self::initPolicy(
            $user,
            self::$dm,
            $phone,
            null,
            true,
            true
        );

        $policy->setStatus(Policy::STATUS_ACTIVE);

        self::$dm->persist($policy);
        self::$dm->flush();

        $claimPage = self::$router->generate('claim_login_token');
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(sprintf('/user/claim')));
    }

    public function testClaimLoginInvalid()
    {
        $this->logout();
        $email = self::generateEmail('testClaimLoginInvalid', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $claimPage = self::$router->generate('claim_login_token', ['tokenId' => 'foo']);
        $crawler = self::$client->request('GET', $claimPage);
        
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(sprintf('/claim')));
    }

    public function testClaimLoginValid()
    {
        $email = self::generateEmail('testClaimLoginValid', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        /** @var Client $redis */
        $redis = $this->getContainer(true)->get('snc_redis.default');
        $token = md5(sprintf('%s%s', time(), $email));
        $redis->setex($token, 900, $user->getId());

        $claimPage = self::$router->generate('claim_login_token', ['tokenId' => $token]);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(sprintf('/user/claim')));
    }
}
