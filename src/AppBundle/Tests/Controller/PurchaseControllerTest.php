<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * @group functional-net
 */
class PurchaseControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    public function tearDown()
    {
    }

    public function testPurchaseOk()
    {
        $crawler = $this->createPurchase(
            self::generateEmail('testPurchase', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-address'));

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

    public function testPurchaseUserPhoneSpace()
    {
        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseUserPhoneSpace', $this),
            'foo bar',
            new \DateTime('1980-01-01'),
            implode(' ', str_split(self::generateRandomMobile(), 1))
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-address'));
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
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-address'));
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

    public function testPurchaseAddress()
    {
        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseAddress', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-address'));

        $crawler = $this->setAddress();

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-phone'));
    }

    public function testPurchasePhone()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhone', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-address'));

        $crawler = $this->setAddress();

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-phone'));

        $crawler = $this->setPhone($phone);
        //print $crawler->html();
        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-review/monthly'));
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

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhone', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-address'));

        $crawler = $this->setAddress();

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-phone'));

        $imei = implode(' ', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-review/monthly'));
    }

    public function testPurchasePhoneImeiSpace()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhone', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-address'));

        $crawler = $this->setAddress();

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-phone'));

        $imei = implode(' ', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-review/monthly'));
    }

    public function testPurchasePhoneImeiDash()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhone', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-address'));

        $crawler = $this->setAddress();

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-phone'));

        $imei = implode('-', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-review/monthly'));
    }

    public function testPurchasePhoneImeiSlash()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhone', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-address'));

        $crawler = $this->setAddress();

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-phone'));

        $imei = implode('/', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-review/monthly'));
    }

    public function testPurchasePhoneImeiS7()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhone', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-address'));

        $crawler = $this->setAddress();

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-phone'));

        $imei = sprintf('%s/71', self::generateRandomImei());
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-review/monthly'));
    }

    public function testPurchaseReviewToJudopay()
    {
        // unable to implement test
        // form post needs to be direct to judopay
        // symfony tests are unable to perform client side events
    }

    public function testPurchaseReviewRequiresAccept()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseReviewRequiresAccept', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-address'));

        $crawler = $this->setAddress();

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-phone'));

        $crawler = $this->setPhone($phone);

        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-review/monthly'));

        $crawler = $this->setReview(null);
        // print $crawler->html();

        self::verifyResponse(200);
    }

    private function createPurchaseUser($user, $name, $birthday)
    {
        $this->createPurchase($user->getEmail(), $name, $birthday, $user->getMobileNumber());
    }

    private function setReview($accept)
    {
        $crawler = self::$client->request('GET', '/purchase/step-review/monthly');
        $form = $crawler->selectButton('form_next')->form();
        if ($accept) {
            $form['form_confirm']->tick();
        }
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function setPhone($phone, $imei = null)
    {
        $crawler = self::$client->request('GET', '/purchase/step-phone');
        $form = $crawler->selectButton('purchase_form[next]')->form();
        if (!$imei) {
            $imei = self::generateRandomImei();
        }
        $form['purchase_form[imei]'] = $imei;
        $form['purchase_form[amount]'] = $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice();
        if ($phone->getMake() == "Apple") {
            // use a different number in case we're testing /, -, etc
            $form['purchase_form[serialNumber]'] = self::generateRandomImei();
        }
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function setAddress()
    {
        $crawler = self::$client->request('GET', '/purchase/step-address');
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[addressLine1]'] = '123 Foo St';
        $form['purchase_form[city]'] = 'Unknown';
        $form['purchase_form[postcode]'] = 'BX1 1LT';
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function createPurchase($email, $name, $birthday, $mobile = null)
    {
        if (!$mobile) {
            $mobile = self::generateRandomMobile();
        }
        $crawler = self::$client->request('GET', '/purchase/');
        self::verifyResponse(200);
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[email]'] = $email;
        $form['purchase_form[name]'] = $name;
        $form['purchase_form[birthday][day]'] = sprintf("%d", $birthday->format('d'));
        $form['purchase_form[birthday][month]'] = sprintf("%d", $birthday->format('m'));
        $form['purchase_form[birthday][year]'] = $birthday->format('Y');
        $form['purchase_form[mobileNumber]'] = $mobile;
        $crawler = self::$client->submit($form);

        return $crawler;
    }
}
