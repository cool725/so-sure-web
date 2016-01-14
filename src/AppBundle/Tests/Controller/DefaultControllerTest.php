<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

/**
 * @group functional
 */
class DefaultControllerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function tearDown()
    {
        
    }
    
    public function testIndex()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testLaunchIndex()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $form = $crawler->selectButton('launch[save]')->form();
        
        // set some values
        $form['launch[name]'] = 'Foo Bar';
        $form['launch[email]'] = 'foo@bar.com';
        // submit the form
        $crawler = $client->submit($form);
        $this->assertEquals(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();
        $this->assertContains('/?referral=', $crawler->text());
    }

    public function testLaunchReferalIndex()
    {
        $client = static::createClient();
        $dm = $this->getManager($client);
        $repo = $dm->getRepository(User::class);
        $fooUser = $repo->findOneBy(['email' => 'foo@bar.com']);

        $crawler = $client->request('GET', sprintf('/?referral=%s', $fooUser->getId()));
        $form = $crawler->selectButton('launch[save]')->form();
        
        // set some values
        $form['launch[name]'] = 'Bar Foo';
        $form['launch[email]'] = 'bar@foo.com';
        // submit the form
        $crawler = $client->submit($form);
        $this->assertEquals(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();
        $this->assertContains('/?referral=', $crawler->text());
        $this->assertNotContains(sprintf('/?referral=%s', $fooUser->getId()), $crawler->text());

        $barUser = $repo->findOneBy(['email' => 'bar@foo.com']);
        $this->assertEquals($fooUser, $barUser->getReferred());
        $this->assertEquals(1, count($fooUser->getReferrals()));
    }

    protected function getManager($client)
    {
        return $client->getContainer()->get('doctrine_mongodb')->getManager();
    }
}
