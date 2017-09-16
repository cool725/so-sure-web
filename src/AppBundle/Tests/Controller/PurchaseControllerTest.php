<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use AppBundle\Classes\Salva;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Document\JudoPaymentMethod;

/**
 * @group functional-net
 */
class PurchaseControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    public function tearDown()
    {
        //self::$client->request('GET', '/logout');
        //self::$client->followRedirect();
        self::$client->getCookieJar()->clear();
    }

    public function testPurchaseOkNew()
    {
        $phone = self::getRandomPhone(self::$dm);
        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $email = self::generateEmail('testPurchaseNew', $this);
        $crawler = $this->createPurchaseNew(
            $email,
            'foo bar',
            new \DateTime('1980-01-01')
        );

        //self::verifyResponse(302);
        //$this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-missing-phone'));

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);
        $user = $userRepo->findOneBy(['emailCanonical' => strtolower($email)]);
        $now = new \DateTime();

        $this->assertNotNull($user->getIdentityLog());
        $diff = $user->getIdentityLog()->getDate()->diff($now);
        $this->assertTrue($diff->days == 0 && $diff->h == 0 && $diff->i == 0);

        $this->assertNotNull($user->getLatestWebIdentityLog());
        $diff = $user->getLatestWebIdentityLog()->getDate()->diff($now);
        $this->assertTrue($diff->days == 0 && $diff->h == 0 && $diff->i == 0);
    }

    public function testPurchaseUserPhoneSpaceNew()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchaseNew(
            self::generateEmail('testPurchaseUserPhoneSpaceNew', $this),
            'foo bar',
            new \DateTime('1980-01-01'),
            implode(' ', str_split(self::generateRandomMobile(), 1))
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));
    }

    public function testPurchaseExistingUserDiffDetailsNew()
    {
        $phone = $this->setRandomPhone();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserDiffDetailsNew', $this),
            'foo'
        );

        $crawler = $this->createPurchaseUserNew($user, 'not me', new \DateTime('1980-01-01'));

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/login'));
    }

    public function testPurchaseExistingUserSameDetailsNew()
    {
        $phone = self::getRandomPhone(self::$dm);
        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserSameDetailsNew', $this),
            'foo',
            self::getRandomPhone(self::$dm)
        );

        $crawler = $this->createPurchaseUserNew($user, 'foo bar', new \DateTime('1980-01-01'));
        self::verifyResponse(302);

        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));
    }

    public function testPurchaseExistingUserSameDetailsWithPartialPolicyNew()
    {
        $phone = self::getRandomPhone(self::$dm);
        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserSameDetailsWithPartialPolicyNew', $this),
            'foo',
            $phone
        );
        self::initPolicy($user, self::$dm, $phone);

        $crawler = $this->createPurchaseUserNew($user, 'foo bar', new \DateTime('1980-01-01'));

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/login'));
    }

    public function testPurchaseAddressNew()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchaseNew(
            self::generateEmail('testPurchaseAddressNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));
    }

    public function testPurchasePhoneNew()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchaseNew(
            self::generateEmail('testPurchasePhoneNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $crawler = $this->setPhoneNew($phone);
        //print $crawler->html();
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneImeiSpaceNineSixtyEightNew()
    {
        $phoneRepo = static::$dm->getRepository(Phone::class);
        $phone = $phoneRepo->findOneBy(['devices' => 'zeroflte', 'memory' => 128]);
        //$phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchaseNew(
            self::generateEmail('testPurchasePhoneImeiSpaceNineSixtyEightNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $imei = implode(' ', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhoneNew($phone, $imei);

        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneImeiSpaceNew()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchaseNew(
            self::generateEmail('testPurchasePhoneImeiSpaceNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $imei = implode(' ', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhoneNew($phone, $imei);

        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneImeiDashNew()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchaseNew(
            self::generateEmail('testPurchasePhoneImeiDashNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $imei = implode('-', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhoneNew($phone, $imei);

        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneImeiSlashNew()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchaseNew(
            self::generateEmail('testPurchasePhoneImeiSlashNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $imei = implode('/', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhoneNew($phone, $imei);

        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneImeiS7New()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchaseNew(
            self::generateEmail('testPurchasePhoneImeiS7New', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $imei = sprintf('%s/71', self::generateRandomImei());
        $crawler = $this->setPhoneNew($phone, $imei);

        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchaseReviewToJudopay()
    {
        // unable to implement test
        // form post needs to be direct to judopay
        // symfony tests are unable to perform client side events
    }

    public function testPurchaseReviewWithAccept()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseReviewRequiresAccept', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $crawler = $this->setPhone($phone);

        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchaseReviewWithAcceptNew()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchaseNew(
            self::generateEmail('testPurchaseReviewRequiresAcceptNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $crawler = $this->setPhoneNew($phone);

        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPayCC()
    {
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $email = self::generateEmail('testPayCC', $this);
        $password = 'foo';
        $phone = self::getRandomPhone($dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            $dm
        );
        $policy = self::initPolicy($user, $dm, $phone);
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setReference($policy->getId());
        $payment->setUser($user);
        $policy->addPayment($payment);
        $dm->flush();

        $response = static::runJudoPayPayment(
            self::$container->get('app.judopay'),
            $user,
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice()
        );

        // change to use a flash message for purchase
        $this->login($email, $password, 'user/invalid');

        $crawler = self::$client->request('POST', '/purchase/cc/success', [
            'Reference' => $policy->getId(),
            'ReceiptId' => $response['receiptId'],
            'CardToken' => $response['consumer']['consumerToken']
        ]);

        self::verifyResponse(302);
        $redirectUrl = self::$router->generate('user_welcome');
        $this->assertTrue(self::$client->getResponse()->isRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testPayCCRetry()
    {
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $email = self::generateEmail('testPayCCRetry', $this);
        $password = 'foo';
        $phone = self::getRandomPhone($dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            $dm
        );
        $policy = self::initPolicy($user, $dm, $phone);
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setReference($policy->getId());
        $payment->setUser($user);
        $policy->addPayment($payment);
        $dm->flush();

        $response = static::runJudoPayPayment(
            self::$container->get('app.judopay'),
            $user,
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice()
        );

        // change to use a flash message for purchase
        $this->login($email, $password, 'user/invalid');

        $crawler = self::$client->request('POST', '/purchase/cc/success', [
            'Reference' => $policy->getId(),
            'ReceiptId' => $response['receiptId'],
            'CardToken' => $response['consumer']['consumerToken']
        ]);

        self::verifyResponse(302);
        $redirectUrl = self::$router->generate('user_welcome');
        $this->assertTrue(self::$client->getResponse()->isRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);

        $crawler = self::$client->request('POST', '/purchase/cc/success', [
            'Reference' => $policy->getId(),
            'ReceiptId' => $response['receiptId'],
            'CardToken' => $response['consumer']['consumerToken']
        ]);

        self::verifyResponse(302);
        // TODO: Current user welcome will only be displayed if one payment has been made,
        // but should check the actual paid amount on policy as well in the future
        $redirectUrl = self::$router->generate('user_welcome');
        $this->assertTrue(self::$client->getResponse()->isRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function test2ndPolicyPayCC()
    {
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $email = self::generateEmail('test2ndPolicyPayCC', $this);
        $password = 'foo';
        $phone1 = self::getRandomPhone($dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone1,
            $dm
        );

        $policy1 = self::initPolicy($user, $dm, $phone1, null, false, false);

        $judopay = self::$container->get('app.judopay');
        $policyService = self::$container->get('app.policy');
        $details = $judopay->testPayDetails(
            $user,
            $policy1->getId(),
            $phone1->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        $policyService->setEnvironment('prod');
        // @codingStandardsIgnoreStart
        $judopay->add(
            $policy1,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd
        $policyService->setEnvironment('test');
        $this->assertTrue($user->hasValidPaymentMethod());

        $phone2 = $this->setRandomPhone();
        $this->login($email, $password, 'user/');

        $crawler = $this->setPhoneNew($phone2, null, 1, false);
        self::verifyResponse(302);
        $redirectUrl = self::$router->generate('user_welcome');
        $this->assertTrue(self::$client->getResponse()->isRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testLeadSource()
    {
        $email = self::generateEmail('testLeadSource', $this);
        $this->setRandomPhone();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar',
                'csrf' => $csrfToken,
            ])
        );
        self::verifyResponse(200);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $leadRepo = $dm->getRepository(Lead::class);
        $lead = $leadRepo->findOneBy(['email' => strtolower($email)]);
        $this->assertNotNull($lead);
        $this->assertEquals(strtolower($email), $lead->getEmail());
        $this->assertEquals('foo bar', $lead->getName());
    }

    public function testLeadSourceMissingParam()
    {
        $email = self::generateEmail('testLeadSourceMissingParam', $this);

        $this->setRandomPhone();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar',
            ])
        );
        self::verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testLeadSourceInvalidCsrf()
    {
        $email = self::generateEmail('testLeadSourceInvalidCsrf', $this);
        $this->setRandomPhone();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf') + '.';

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar',
                'csrf' => $csrfToken,
            ])
        );
        self::verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testLeadSourceBadName()
    {
        $email = self::generateEmail('testLeadSourceBadName', $this);
        $this->setRandomPhone();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar bar',
                'csrf' => $csrfToken,
            ])
        );
        self::verifyResponse(200);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $leadRepo = $dm->getRepository(Lead::class);
        $lead = $leadRepo->findOneBy(['email' => strtolower($email)]);
        $this->assertNotNull($lead);
        $this->assertEquals(strtolower($email), $lead->getEmail());
        $this->assertNull($lead->getName());
    }

    private function createPurchaseUser($user, $name, $birthday)
    {
        $this->createPurchase($user->getEmail(), $name, $birthday, $user->getMobileNumber());
    }

    private function createPurchaseUserNew($user, $name, $birthday)
    {
        $this->createPurchaseNew($user->getEmail(), $name, $birthday, $user->getMobileNumber());
    }

    private function verifyPurchaseReady($crawler)
    {
        $form = $crawler->filterXPath('//form[@id="webpay-form"]')->form();
        $this->assertContains('judopay', $form->getUri());
    }

    private function setPhone($phone, $imei = null, $agreed = 1)
    {
        $crawler = self::$client->request('GET', '/purchase/step-policy?force_result=original');
        $form = $crawler->selectButton('purchase_form[next]')->form();
        if (!$imei) {
            $imei = self::generateRandomImei();
        }
        $form['purchase_form[imei]'] = $imei;
        $form['purchase_form[amount]'] = $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice();
        if ($agreed) {
            try {
                $form['purchase_form[agreed]'] = $agreed;
            } catch (\Exception $e) {
                $form['purchase_form[agreed]'] = 'checked';
            }
        }
        if ($phone->getMake() == "Apple") {
            // use a different number in case we're testing /, -, etc
            $form['purchase_form[serialNumber]'] = self::generateRandomImei();
        }
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function setPhoneNew($phone, $imei = null, $agreed = 1, $nextButton = true)
    {
        $crawler = self::$client->request('GET', '/purchase/step-policy?force_result=new');
        if ($nextButton) {
            $form = $crawler->selectButton('purchase_form[next]')->form();
        } else {
            $form = $crawler->selectButton('purchase_form[existing]')->form();
        }
        if (!$imei) {
            $imei = self::generateRandomImei();
        }
        $form['purchase_form[imei]'] = $imei;
        $form['purchase_form[amount]'] = $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice();
        if ($agreed) {
            try {
                $form['purchase_form[agreed]'] = $agreed;
            } catch (\Exception $e) {
                $form['purchase_form[agreed]'] = 'checked';
            }
        }
        if ($phone->getMake() == "Apple") {
            // use a different number in case we're testing /, -, etc
            $form['purchase_form[serialNumber]'] = self::generateRandomImei();
        }
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function createPurchase($email, $name, $birthday, $mobile = null)
    {
        if (!$mobile) {
            $mobile = self::generateRandomMobile();
        }
        $crawler = self::$client->request('GET', '/purchase/?force_result=original');
        self::verifyResponse(200);
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[email]'] = $email;
        $form['purchase_form[name]'] = $name;
        $form['purchase_form[birthday]'] = sprintf("%s", $birthday->format('d/m/Y'));
        $form['purchase_form[mobileNumber]'] = $mobile;
        $form['purchase_form[addressLine1]'] = '123 Foo St';
        $form['purchase_form[city]'] = 'Unknown';
        $form['purchase_form[postcode]'] = 'BX1 1LT';
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function createPurchaseNew($email, $name, $birthday, $mobile = null)
    {
        if (!$mobile) {
            $mobile = self::generateRandomMobile();
        }
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[email]'] = $email;
        $form['purchase_form[name]'] = $name;
        $form['purchase_form[birthday]'] = sprintf("%s", $birthday->format('d/m/Y'));
        $form['purchase_form[mobileNumber]'] = $mobile;
        $form['purchase_form[addressLine1]'] = '123 Foo St';
        $form['purchase_form[city]'] = 'Unknown';
        $form['purchase_form[postcode]'] = 'BX1 1LT';
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function setRandomPhone()
    {
        $phone = self::getRandomPhone(self::$dm);
        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        return $phone;
    }
}
