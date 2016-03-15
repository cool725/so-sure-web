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

    // Manual test

    /**
     * This is a test that can be manually run by uncommenting exception in login
     * Purely to test that boilerplate exception login & return codes work
     */
    /*
    public function testManual()
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
        $this->assertEquals(500, $client->getResponse()->getStatusCode());
    }
    */

    // login

    /**
     *
     */
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
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(101, $data['code']);
    }

    public function testLoginMissingPasswordParam()
    {
        $client = static::createClient();
        $crawler = $client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('username' => 'foo')))
        );
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    public function testLoginMissingUserParam()
    {
        $client = static::createClient();
        $crawler = $client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('password' => 'bar')))
        );
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
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
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(100, $data['code']);
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
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('foo@api.bar.com', $data['email']);
    }

    // quote
    
    /**
     *
     */
    public function testQuoteUnknown()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/quote');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(false, $data['device_found']);
        $this->assertTrue(count($data['quotes']) > 2);
        // Make sure we're not returning all the quotes
        $this->assertTrue(count($data['quotes']) < 10);
    }

    public function testQuoteX3()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/quote?device=x3');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(1, count($data['quotes']));
    }

    public function testQuoteMemoryOptions()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/quote?device=iPhone%206');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(true, $data['device_found']);
        $this->assertTrue(count($data['quotes']) > 1);
        // Make sure we're not returning all the quotes
        $this->assertTrue(count($data['quotes']) < 10);
    }
    
    // referral
    
    /**
     *
     */
    public function testReferralInvalid()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/referral?email=abc');
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
    }

    public function testReferral()
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'referral@api.bar.com', 'bar');

        $crawler = $client->request('GET', sprintf('/api/v1/referral?email=%s', $user->getEmail()));
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertContains("http://goo.gl", $data['url']);
    }

    public function testReferralCreate()
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'origin@api.bar.com', 'bar');
        $userReferred = $this->createUser($client, 'referred@api.bar.com', 'bar');

        $crawler = $client->request(
            'POST',
            '/api/v1/referral',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array(
                'email' => $userReferred->getEmail(),
                'referral_code' => $user->getId(),
            ), 'identity' => []))
        );
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue(strlen($data['url']) > 0);

        $dm = $this->getManager($client);
        $repo = $dm->getRepository(User::class);
        $fooUser = $repo->findOneBy(['email' => 'referred@api.bar.com']);
        $this->assertTrue($fooUser->getReferred()->getId() === $user->getId());
    }

    public function testReferralCreateInvalid()
    {
        $client = static::createClient();

        $crawler = $client->request(
            'POST',
            '/api/v1/referral',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array(
                'email' => 'foo',
                'referral_code' => 'foo',
            ), 'identity' => []))
        );
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
    }

    // sns

    /**
     *
     */
    public function testSns()
    {
        $client = static::createClient();

        $crawler = $client->request(
            'POST',
            '/api/v1/sns',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array(
                // @codingStandardsIgnoreStart
                'endpoint' => 'arn:aws:sns:eu-west-1:812402538357:endpoint/GCM/so-sure_android/344008b8-a266-3d7b-baa4-f1e8cf9fc16e'
                // @codingStandardsIgnoreEnd
            ), 'identity' => []))
        );
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testSnsMissingEndpoint()
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

    // token

    /**
     *
     */
    public function testTokenOk()
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
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue(strlen($data['token']) > 20);
    }

    public function testTokenBad()
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

    public function testTokenMissing()
    {
        $client = static::createClient();

        $crawler = $client->request(
            'POST',
            '/api/v1/token',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => [], 'identity' => []))
        );
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    // user

    /**
     *
     */
    public function testUserDuplicate()
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'dup-user@api.bar.com', 'bar');

        $crawler = $client->request(
            'POST',
            '/api/v1/user',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('email' => 'dup-user@api.bar.com')))
        );
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
    }

    public function testUserCreate()
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
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('api-new-user@api.bar.com', $data['email']);

        $dm = $this->getManager($client);
        $repo = $dm->getRepository(User::class);
        $fooUser = $repo->findOneBy(['email' => 'api-new-user@api.bar.com']);
        $this->assertTrue($fooUser !== null);
    }

    // helpers

    /**
     *
     */
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
