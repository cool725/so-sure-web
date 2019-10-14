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
        self::$client->getCookieJar()->clear();
    }

    public function testPhoneSearchPhoneInsuranceByPhoneName()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/apple+iphone+7');
        $this->assertEquals(200, $this->getClientResponseStatusCode());
        $this->assertHasFormAction($crawler, '/phone-search-dropdown');
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

        // should be redirected to redirect url
        $this->assertEquals(301, $this->getClientResponseStatusCode());
        $this->assertEquals($redirectUrl, $this->getClientResponseTargetUrl());
        $crawler = self::$client->followRedirect();

        $this->assertHasFormAction($crawler, '/phone-search-dropdown');
    }

    public function testSessionPurchasePhone()
    {
        self::$client->request('GET', '/purchase-phone/apple+iphone+7+32GB');

        $this->assertRedirectionPath('/purchase');
        // should be redirected to redirect url
        $this->assertEquals(302, $this->getClientResponseStatusCode());
        $this->assertEquals(
            '/purchase',
            $this->getClientResponseTargetUrl(),
            "Expected '/purchase' to match '{$this->getClientResponseTargetUrl()}'"
        );
        self::$client->followRedirect();
    }

    public function testSessionPurchasePhoneHistorical()
    {
        self::$client->request('GET', '/purchase-phone/Apple+iPhone+7+32GB');

        // should be redirected to redirect url
        $this->assertEquals(302, $this->getClientResponseStatusCode());
        $this->assertEquals('/purchase', $this->getClientResponseTargetUrl());
        $crawler = self::$client->followRedirect();
        $this->assertContains('Apple iPhone 7', $crawler->html());
    }

    public function testSessionPurchasePhoneNotFound()
    {
        self::$client->request('GET', '/purchase-phone/Apple+p+7+32GB');
        $this->assertEquals(404, $this->getClientResponseStatusCode());
    }

    public function testPhoneSearchLearnMore()
    {
        $alternate = [];
        $crawler = self::$client->request('GET', '/phone-insurance/Apple+iPhone+7+256GB/learn-more');
        $this->assertEquals(200, $this->getClientResponseStatusCode());
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
        $this->assertEquals(302, $this->getClientResponseStatusCode());
        $crawler = self::$client->followRedirect();
        $this->assertEquals(
            sprintf('http://localhost/'),
            self::$client->getHistory()->current()->getUri()
        );
        $this->assertHasFormAction($crawler, '/phone-search-dropdown');
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
        $this->assertEquals(302, $this->getClientResponseStatusCode());
        $crawler = self::$client->followRedirect();
        $this->assertEquals(
            sprintf('http://localhost/'),
            self::$client->getHistory()->current()->getUri()
        );

        $this->assertHasFormAction($crawler, '/phone-search-dropdown');
    }

    public function testPhoneSearchInsuranceCrackedScreen()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/cracked-screen');
        $this->assertEquals(200, $this->getClientResponseStatusCode());
        $this->assertHasFormAction($crawler, '/phone-search-dropdown');
    }

    public function testPhoneSearchInsuranceTheft()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/theft');
        $this->assertEquals(200, $this->getClientResponseStatusCode());
        $this->assertHasFormAction($crawler, '/phone-search-dropdown');
    }

    public function testPhoneSearchInsuranceWaterDamage()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/water-damage');
        $this->assertEquals(200, $this->getClientResponseStatusCode());
        $this->assertHasFormAction($crawler, '/phone-search-dropdown');
    }

    public function testPhoneSearchInsuranceBrokenPhone()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/broken-phone');
        $this->assertEquals(200, $this->getClientResponseStatusCode());
        $this->assertHasFormAction($crawler, '/phone-search-dropdown');
    }

    public function testPhoneSearchInsuranceLost()
    {
        $crawler = self::$client->request('GET', '/phone-insurance/loss');
        $this->assertEquals(200, $this->getClientResponseStatusCode());
        $this->assertHasFormAction($crawler, '/phone-search-dropdown');
    }

    public function testQuoteMe()
    {
        self::$client->request('GET', '/quote-me/Apple+iPhone+XS+256GB');
        $decoded = (array) json_decode($this->getClientResponseContent());
        $this->assertArrayHasKey('phoneId', $decoded);
        $this->assertArrayHasKey('price', $decoded);
        $this->assertArrayHasKey('monthlyPremium', (array) $decoded['price']);
        $this->assertArrayHasKey('yearlyPremium', (array) $decoded['price']);
        $this->assertArrayHasKey('productOverrides', $decoded);
        $this->assertArrayHasKey('excesses', (array) $decoded['productOverrides']);
        $this->assertEquals(4, count($decoded['productOverrides']->excesses));
        $this->assertArrayHasKey('picsureExcesses', (array) $decoded['productOverrides']);
        $this->assertEquals(4, count($decoded['productOverrides']->picsureExcesses));
        $this->assertArrayHasKey('purchaseUrlRedirect', $decoded);
    }

    public function testQuoteMeError()
    {
        self::$client->request('GET', '/quote-me/Fake+Phone+256GB');
        $this->assertEquals(404, $this->getClientResponseStatusCode());
    }

    public function testListPhones()
    {
        self::$client->request('GET', '/list-phones');
        $decoded = (array) json_decode($this->getClientResponseContent());
        $this->assertGreaterThan(0, count($decoded));

        // Check first phone in list is valid
        $this->assertArrayHasKey('id', (array) $decoded[0]);
        $this->assertArrayHasKey('make', (array) $decoded[0]);
        $this->assertArrayHasKey('model', (array) $decoded[0]);
        $this->assertArrayHasKey('aggregatorId', (array) $decoded[0]);
    }

    public function testListPhonesWithAggregatorGoCompare()
    {
        self::$client->request('GET', '/list-phones?aggregator=GoCompare');
        $decoded = (array) json_decode($this->getClientResponseContent());
        $this->assertGreaterThan(0, count($decoded));
        // Check first phone in list is valid
        $this->assertArrayHasKey('id', (array) $decoded[0]);
        $this->assertArrayHasKey('make', (array) $decoded[0]);
        $this->assertArrayHasKey('model', (array) $decoded[0]);
        $this->assertArrayHasKey('memory', (array) $decoded[0]);
        $this->assertArrayHasKey('aggregatorId', (array) $decoded[0]);
        // Make sure at least one of the phones has an aggregator ID
        $found = false;
        foreach ($decoded as $phone) {
            if ($phone->aggregatorId != '') {
                $found = true;
            }
        }
        $this->assertEquals(true, $found);
    }
}
