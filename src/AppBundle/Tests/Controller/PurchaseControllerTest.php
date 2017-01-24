<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * @group functional-net
 */
class PurchaseControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function tearDown()
    {
    }

    public function testPurchaseOk()
    {
        $crawler = self::$client->request('GET', '/purchase/');
        self::verifyResponse(200);
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[email]'] = self::generateEmail('testPurchase', $this);
        $form['purchase_form[name]'] = 'foo bar';
        $form['purchase_form[birthday][day]'] = 1;
        $form['purchase_form[birthday][month]'] = 1;
        $form['purchase_form[birthday][year]'] = 1980;
        $form['purchase_form[mobileNumber]'] = self::generateRandomMobile();
        $crawler = self::$client->submit($form);
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

        $crawler = self::$client->request('GET', '/purchase/');
        self::verifyResponse(200);
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[email]'] = $user->getEmail();
        $form['purchase_form[name]'] = 'not me';
        $form['purchase_form[birthday][day]'] = 1;
        $form['purchase_form[birthday][month]'] = 1;
        $form['purchase_form[birthday][year]'] = 1980;
        $form['purchase_form[mobileNumber]'] = self::generateRandomMobile();
        $crawler = self::$client->submit($form);
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

        $crawler = self::$client->request('GET', '/purchase/');
        self::verifyResponse(200);
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[email]'] = $user->getEmail();
        $form['purchase_form[name]'] = 'foo bar';
        $form['purchase_form[birthday][day]'] = 1;
        $form['purchase_form[birthday][month]'] = 1;
        $form['purchase_form[birthday][year]'] = 1980;
        $form['purchase_form[mobileNumber]'] = $user->getMobileNumber();
        $crawler = self::$client->submit($form);
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

        $crawler = self::$client->request('GET', '/purchase/');
        self::verifyResponse(200);
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[email]'] = $user->getEmail();
        $form['purchase_form[name]'] = 'foo bar';
        $form['purchase_form[birthday][day]'] = 1;
        $form['purchase_form[birthday][month]'] = 1;
        $form['purchase_form[birthday][year]'] = 1980;
        $form['purchase_form[mobileNumber]'] = $user->getMobileNumber();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/login'));
    }
}
