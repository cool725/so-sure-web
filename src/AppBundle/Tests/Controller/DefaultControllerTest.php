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

    public function testIndexRedirect()
    {
        $crawler = self::$client->request('GET', '/', [], [], ['REMOTE_ADDR' => '70.248.28.23']);
        self::verifyResponse(302);
    }

    public function testIndexFacebookNoRedirect()
    {
        $crawler = self::$client->request('GET', '/', [], [], [
            'REMOTE_ADDR' => '70.248.28.23',
            'HTTP_User-Agent' => "facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)"
        ]);
        self::verifyResponse(200);
    }

    public function testIndexTwitterNoRedirect()
    {
        $crawler = self::$client->request('GET', '/', [], [], [
            'REMOTE_ADDR' => '70.248.28.23',
            'HTTP_User-Agent' => "twitterbot"
        ]);
        self::verifyResponse(200);
    }

    public function testQuotePhoneRouteMakeModelMemory()
    {
        $url = self::$router->generate('quote_make_model_memory', [
            'make' => 'Apple',
            'model' => 'iPhone+5S',
            'memory' => 64,
        ]);

        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
    }

    public function testQuotePhoneSpaceRouteMakeModelMemory()
    {
        $url = self::$router->generate('quote_make_model_memory', [
            'make' => 'Apple',
            'model' => 'iPhone 5S',
            'memory' => 64,
        ]);
        $redirectUrl = self::$router->generate('quote_make_model_memory', [
            'make' => 'Apple',
            'model' => 'iPhone+5S',
            'memory' => 64,
        ]);

        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(301);
        $this->assertTrue(self::$client->getResponse()->isRedirect($redirectUrl));
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testQuotePhoneRouteMakeModel()
    {
        $crawler = self::$client->request('GET', self::$router->generate('quote_make_model', [
            'make' => 'Apple',
            'model' => 'iPhone+5S',
        ]));
        self::verifyResponse(200);
    }

    public function testQuotePhoneSpaceRouteMakeModel()
    {
        $url = self::$router->generate('quote_make_model', [
            'make' => 'Apple',
            'model' => 'iPhone 5S',
        ]);
        $redirectUrl = self::$router->generate('quote_make_model', [
            'make' => 'Apple',
            'model' => 'iPhone+5S',
        ]);

        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(301);
        $this->assertTrue(self::$client->getResponse()->isRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testQuotePhone()
    {
        $repo = self::$dm->getRepository(Phone::class);
        $phone = $repo->findOneBy(['devices' => 'iPhone 6', 'memory' => 64]);

        $crawler = self::$client->request('GET', self::$router->generate('quote_phone', [
            'id' => $phone->getId()
        ]));
        self::verifyResponse(301);
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
        $this->assertContains(
            sprintf("£%.2f", $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice()),
            self::$client->getResponse()->getContent()
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
            self::$client->getResponse()->getContent()
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
            self::$client->getResponse()->getContent()
        );
    }
}
