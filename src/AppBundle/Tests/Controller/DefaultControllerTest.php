<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
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
        $form = $crawler->selectButton('launch_phone[next]')->form();
        $values = [];
        foreach ($form->all() as $field) {
            if ($field instanceof ChoiceFormField) {
                $values = $field->availableOptionValues();
            }
        }
        $this->assertGreaterThan(10, count($values));
    }

    public function testQuotePhoneRouteMakeModelMemory()
    {
        $crawler = self::$client->request('GET', self::$router->generate('quote_make_model_memory', [
            'make' => 'Apple',
            'model' => 'iPhone 5',
            'memory' => 64,
        ]));
        self::verifyResponse(200);
    }

    public function testQuotePhoneRouteMakeModel()
    {
        $crawler = self::$client->request('GET', self::$router->generate('quote_make_model', [
            'make' => 'Apple',
            'model' => 'iPhone 5',
        ]));
        self::verifyResponse(200);
    }

    public function testQuotePhone()
    {
        $repo = self::$dm->getRepository(Phone::class);
        $phone = $repo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);

        $crawler = self::$client->request('GET', self::$router->generate('quote_phone', [
            'id' => $phone->getId()
        ]));
        self::verifyResponse(200);
        $this->assertContains(
            sprintf("£%.2f", $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice()),
            self::$client->getResponse()->getContent()
        );
    }
}
