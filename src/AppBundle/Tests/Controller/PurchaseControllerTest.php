<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use AppBundle\Classes\Salva;

/**
 * @group functional-net
 */
class PurchaseControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    public function tearDown()
    {
        self::$client->request('GET', '/logout');
        self::$client->followRedirect();
        self::$client->getCookieJar()->clear();
    }

    public function testPurchaseOk()
    {
        $crawler = $this->createPurchase(
            self::generateEmail('testPurchase', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-missing-phone'));

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);
        $user = $userRepo->findOneBy(['emailCanonical' => strtolower(self::generateEmail('testPurchase', $this))]);
        $now = new \DateTime();

        $this->assertNotNull($user->getIdentityLog());
        $diff = $user->getIdentityLog()->getDate()->diff($now);
        $this->assertTrue($diff->days == 0 && $diff->h == 0 && $diff->i == 0);

        $this->assertNotNull($user->getLatestWebIdentityLog());
        $diff = $user->getLatestWebIdentityLog()->getDate()->diff($now);
        $this->assertTrue($diff->days == 0 && $diff->h == 0 && $diff->i == 0);
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

    public function testPurchaseUserPhoneSpace()
    {
        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseUserPhoneSpace', $this),
            'foo bar',
            new \DateTime('1980-01-01'),
            implode(' ', str_split(self::generateRandomMobile(), 1))
        );

        self::verifyResponse(302);
        //print $crawler->html();
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-missing-phone'));
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

    public function testPurchaseExistingUserDiffDetails()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserDiffDetails', $this),
            'foo'
        );

        $crawler = $this->createPurchaseUser($user, 'not me', new \DateTime('1980-01-01'));

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/login'));
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

    public function testPurchaseExistingUserSameDetails()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserSameDetails', $this),
            'foo',
            self::getRandomPhone(self::$dm)
        );

        $crawler = $this->createPurchaseUser($user, 'foo bar', new \DateTime('1980-01-01'));
        self::verifyResponse(302);

        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-missing-phone'));
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

    public function testPurchaseExistingUserSameDetailsWithPartialPolicy()
    {
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserSameDetailsWithPartialPolicy', $this),
            'foo',
            $phone
        );
        self::initPolicy($user, self::$dm, $phone);

        $crawler = $this->createPurchaseUser($user, 'foo bar', new \DateTime('1980-01-01'));

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/login'));
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

    public function testPurchaseAddress()
    {
        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseAddress', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-missing-phone'));
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

    public function testPurchasePhone()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhone', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $crawler = $this->setPhone($phone);
        //print $crawler->html();
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
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

    public function testPurchasePhoneImeiSpaceNineSixtyEight()
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

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiSpaceNineSixtyEight', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $imei = implode(' ', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

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

    public function testPurchasePhoneImeiSpace()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiSpace', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $imei = implode(' ', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

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

    public function testPurchasePhoneImeiDash()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiDash', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $imei = implode('-', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

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

    public function testPurchasePhoneImeiSlash()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiSlash', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $imei = implode('/', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

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

    public function testPurchasePhoneImeiS7()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiS7', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $imei = sprintf('%s/71', self::generateRandomImei());
        $crawler = $this->setPhone($phone, $imei);

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

    public function testPurchaseReviewWithoutAccept()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseReviewWithoutAccept', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $crawler = $this->setPhone($phone, null, null);

        self::verifyResponse(200);
        $this->verifyPurchaseNotReady($crawler);
    }

    public function testPurchaseReviewWithoutAcceptNew()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchaseNew(
            self::generateEmail('testPurchaseReviewWithoutAcceptNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-policy'));

        $crawler = $this->setPhoneNew($phone, null, null);

        self::verifyResponse(200);
        $this->verifyPurchaseNotReady($crawler);
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

        $this->login($email, $password, 'purchase/step-policy');

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

        $this->login($email, $password, 'purchase/step-policy');

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
    
    private function verifyPurchaseNotReady($crawler)
    {
        $form = $crawler->filterXPath('//form[@id="webpay-form"]')->form();
        $this->assertNotContains('judopay', $form->getUri());
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

    private function setPhoneNew($phone, $imei = null, $agreed = 1)
    {
        $crawler = self::$client->request('GET', '/purchase/step-policy?force_result=new');
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
