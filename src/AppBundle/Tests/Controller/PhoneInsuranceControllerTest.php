<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\Phone;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\Invitation\EmailInvitation;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\PhoneInsuranceControllerTest
 */
class PhoneInsuranceControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function tearDown()
    {
    }

    public function testPhoneSearchPhoneInsuranceByPhoneName()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/apple+iphone+7');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        $this->assertHasFormAction($crawler, '/select-phone-dropdown');
    }

    public function testPhoneSearchPhoneInsuranceByPhoneId()
    {
        $phoneRepo = self::$dm->getRepository(Phone::class);
        /** @var Phone $phone */
        $phone =  $phoneRepo->findOneBy(['make' => 'Apple', 'model' => 'iPhone 7']);
        $url = sprintf('/phone-insurance/%s', $phone->getId());
        $redirectUrl = sprintf(
            '/phone-insurance/%s+%s+%sGB',
            $phone->getMakeCanonical(),
            $phone->getEncodedModelCanonical(),
            $phone->getMemory()
        );
        $crawler = self::$client->request('GET', $url);
        $data = self::$client->getResponse();

        // should be redirected to redirect url
        $this->assertEquals(301, $data->getStatusCode());
        $this->assertEquals($redirectUrl, $data->getTargetUrl());
        $crawler = self::$client->followRedirect();

        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }

    public function testSessionPurchasePhone()
    {
        $crawler = self::$client->request('GET', '/purchase-phone/apple+iphone+7+32GB');
        $data = self::$client->getResponse();

        // should be redirected to redirect url
        $this->assertEquals(302, $data->getStatusCode());
        $this->assertEquals('/purchase/', $data->getTargetUrl());
        $crawler = self::$client->followRedirect();
    }

    public function testSessionPurchasePhoneHistorical()
    {
        $crawler = self::$client->request('GET', '/purchase-phone/Apple+iPhone+7+32GB');
        $data = self::$client->getResponse();

        // should be redirected to redirect url
        $this->assertEquals(302, $data->getStatusCode());
        $this->assertEquals('/purchase/', $data->getTargetUrl());
        $crawler = self::$client->followRedirect();
    }

    public function testSessionPurchasePhoneNotFound()
    {
        $crawler = self::$client->request('GET', '/purchase-phone/Apple+p+7+32GB');
        $data = self::$client->getResponse();

        $this->assertEquals(404, $data->getStatusCode());
    }

    public function testPhoneSearchLearnMore()
    {
        $alternate = [];
        $crawler = self::$client->request('GET', '/phone-insurance/Apple+iPhone+7+256GB/learn-more');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        foreach ($crawler->filter('.memory-dropdown')->filter('li')->filter('a') as $li) {
            $link = $li->getAttribute('href');
            if ($link == '#') {
                continue;
            }
            $alternate[$li->nodeValue] = $li->getAttribute('href');
        }
        //expecting 2 alternate iphones in drop down lost
        $this->assertEquals(2, count($alternate));
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance//learn-more', 1);
    }

    public function testPhoneSearchPhoneInsuranceSamsung()
    {
        //make sure phone is highlighted
        $phoneRepo = self::$dm->getRepository(Phone::class);
        /** @var Phone $phone */
        $phone = $phoneRepo->findOneBy(['make' => 'Samsung', 'active' => true]);
        $phone->setHighlight(true);
        self::$dm->flush();
        $crawler = self::$client->request('GET', '/phone-insurance/Samsung');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 2);
    }

    public function testPhoneSearchInsureSamsung()
    {
        //make sure phone is
        $phoneRepo = self::$dm->getRepository(Phone::class);
        /** @var Phone $phone */
        $phone = $phoneRepo->findOneBy(['make' => 'Samsung', 'active' => true]);
        $phone->setHighlight(true);
        self::$dm->flush();
        $crawler = self::$client->request('GET', '/insure/Samsung');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 2);
    }

    public function testPhoneSearchInsuranceCrackedScreen()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/cracked-screen');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }

    public function testPhoneSearchInsuranceTheft()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/theft');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }

    public function testPhoneSearchInsuranceWaterDamage()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/water-damage');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }

    public function testPhoneSearchInsuranceBrokenPhone()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/broken-phone');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }

    public function testPhoneSearchInsuranceLost()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/loss');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }
}
