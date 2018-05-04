<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\PhonePrice;
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

    public function testTagManager()
    {
        $crawler = self::$client->request('GET', '/');
        self::verifyResponse(200);
        $tag = self::$client->getContainer()->getParameter('ga_tag_manager_env');
        $body = self::$client->getResponse()->getContent();
        
        // Not a perfect test, but unable to test js code via symfony client
        // This should at least detect if the custom tag manager code environment was accidental removed
        $this->assertTrue(mb_stripos($body, $tag) !== false);
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
        $this->assertTrue(self::$client->getResponse()->isRedirect($redirectUrl), json_encode($crawler->html()));
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
        $this->assertTrue(self::$client->getResponse()->isRedirect($redirectUrl), $crawler->html());
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testQuotePhone()
    {
        $repo = self::$dm->getRepository(Phone::class);
        /** @var Phone $phone */
        $phone = $repo->findOneBy(['devices' => 'iPhone 6', 'memory' => 64]);

        $crawler = self::$client->request('GET', self::$router->generate('quote_phone', [
            'id' => $phone->getId()
        ]));
        self::verifyResponse(301);
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
        /** @var PhonePrice $price */
        $price = $phone->getCurrentPhonePrice();
        $this->assertContains(
            sprintf("£%.2f", $price->getMonthlyPremiumPrice()),
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
    public function testPhoneSearchVSGadget()
    {
        $crawler = self::$client->request('GET', '/so-sure-vs-gadget-cover-phone-insurance');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }

    public function testPhoneSearchVSHalifax()
    {
        $crawler = self::$client->request('GET', '/so-sure-vs-halifax-phone-insurance');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }

    public function testPhoneSearchVSThree()
    {
        $crawler = self::$client->request('GET', '/so-sure-vs-three-phone-insurance');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }

    public function testPhoneSearchHomepage()
    {
        $crawler = self::$client->request('GET', '/');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 2);
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
}
