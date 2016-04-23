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
            'make' => 'HTC',
            'model' => 'Desire',
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
            sprintf("£%.2f", $phone->getCurrentPolicyPremium()->getPolicyPrice()),
            self::$client->getResponse()->getContent()
        );
    }

    public function testLaunchIndex()
    {
        $crawler = self::$client->request('GET', '/');
        $form = $crawler->selectButton('launch_bottom[save]')->form();
        
        // set some values
        $form['launch_bottom[email]'] = 'foo@bar.com';
        // submit the form
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $crawler = self::$client->followRedirect();
        $this->assertContains('http://goo.gl', $crawler->text());
    }

    public function testLaunchRetryIndex()
    {
        $repo = self::$dm->getRepository(User::class);
        $fooUser = $repo->findOneBy(['email' => 'foo@bar.com']);

        $crawler = self::$client->request('GET', '/');
        $form = $crawler->selectButton('launch_bottom[save]')->form();
        
        // set some values
        $form['launch_bottom[email]'] = 'foo@bar.com';
        // submit the form
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $crawler = self::$client->followRedirect();
        $this->assertContains('http://goo.gl', $crawler->text());
    }

    public function testLaunchReferalIndex()
    {
        $repo = self::$dm->getRepository(User::class);
        $fooUser = $repo->findOneBy(['email' => 'foo@bar.com']);
        $this->assertTrue($fooUser !== null);

        $crawler = self::$client->request('GET', sprintf('/?referral=%s', $fooUser->getId()));
        $form = $crawler->selectButton('launch_bottom[save]')->form();

        // set some values
        $form['launch_bottom[email]'] = 'bar@foo.com';
        // submit the form
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $crawler = self::$client->followRedirect();
        $this->assertContains('http://goo.gl', $crawler->text());

        $barUser = $repo->findOneBy(['email' => 'bar@foo.com']);
        $this->assertEquals($fooUser->getId(), $barUser->getReferred()->getId());
        $this->assertEquals(1, count($fooUser->getReferrals()));
    }
}
