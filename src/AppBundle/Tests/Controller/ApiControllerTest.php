<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

/**
 * @group functional
 */
class ApiControllerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function tearDown()
    {
    }

    public function testQuote()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/quote');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testBlankReferral()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/referral?user_id=abc');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent());
        $this->assertEquals(null, $data->{'url'});
    }

    public function testReferral()
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'referral@api.bar.com', 'bar');

        $crawler = $client->request('GET', sprintf('/api/v1/referral?user_id=%s', $user->getId()));
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent());
        $this->assertContains("http://goo.gl", $data->{'url'});
    }

    public function testLoginNoUser()
    {
        $client = static::createClient();
        $crawler = $client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('username' => 'foo', 'password' => 'bar')))
        );
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent());
        $this->assertEquals(101, $data->{'code'});
    }

    public function testLoginBadPassword()
    {
        $client = static::createClient();

        $userManager = $client->getContainer()->get('fos_user.user_manager');
        $user = $userManager->createUser();
        $user->setUsername('foo');
        $user->setPlainPassword('bar');
        $userManager->updateUser($user, true);

        $crawler = $client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('username' => 'foo', 'password' => 'barfoo')))
        );
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent());
        $this->assertEquals(100, $data->{'code'});
    }

    public function testLoginOk()
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'foo@api.bar.com', 'bar');

        $crawler = $client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('username' => 'foo@api.bar.com', 'password' => 'bar'), 'identity' => []))
        );
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent());
        $this->assertEquals('foo@api.bar.com', $data->{'email'});
    }

    public function testOkToken()
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'token@api.bar.com', 'bar');

        $crawler = $client->request(
            'POST',
            '/api/v1/token',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('token' => $user->getToken()), 'identity' => []))
        );
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent());
        $this->assertTrue(strlen($data->{'token'}) > 20);
    }

    public function testBadToken()
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'badtoken@api.bar.com', 'bar');

        $crawler = $client->request(
            'POST',
            '/api/v1/token',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('token' => $user->getToken() + 'bad'), 'identity' => []))
        );
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
    }

    public function testCreateUser()
    {
        $client = static::createClient();

        $crawler = $client->request(
            'POST',
            '/api/v1/user',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('email' => 'api-new-user@api.bar.com')))
        );
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent());
        $this->assertEquals('api-new-user@api.bar.com', $data->{'email'});

        $dm = $this->getManager($client);
        $repo = $dm->getRepository(User::class);
        $fooUser = $repo->findOneBy(['email' => 'api-new-user@api.bar.com']);
        $this->assertTrue($fooUser !== null);
    }

    public function testSns()
    {
        $client = static::createClient();

        $crawler = $client->request(
            'POST',
            '/api/v1/sns',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('endpoint' => 'arn:aws:sns:eu-west-1:812402538357:endpoint/GCM/so-sure_android/344008b8-a266-3d7b-baa4-f1e8cf9fc16e'), 'identity' => []))
        );
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testMissingEndpointSns()
    {
        $client = static::createClient();

        $crawler = $client->request(
            'POST',
            '/api/v1/sns',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array(), 'identity' => []))
        );
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    protected function createUser($client, $email, $password)
    {
        $userManager = $client->getContainer()->get('fos_user.user_manager');
        $user = $userManager->createUser();
        $user->setEmail($email);
        $user->setPlainPassword($password);
        $userManager->updateUser($user, true);

        return $user;
    }

    protected function getManager($client)
    {
        return $client->getContainer()->get('doctrine_mongodb')->getManager();
    }
}
