<?php

namespace AppBundle\Tests\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Listener\UserListener;
use AppBundle\Event\UserEmailEvent;

class BaseControllerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $client;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $identity;
    protected static $jwt;
    protected static $router;
    /** @var Client */
    protected static $redis;
    protected static $invitationService;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        self::$client = self::createClient();
        self::$container = self::$client->getContainer();
        self::$identity = self::$container->get('app.cognito.identity');
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$router = self::$container->get('router');
        self::$jwt = self::$container->get('app.jwt');
        self::$redis = self::$container->get('snc_redis.default');
        self::$policyService = self::$container->get('app.policy');
        self::$invitationService = self::$container->get('app.invitation');
    }
    
    public function setUp()
    {
        parent::setUp();
        $this->expectNoUserChangeEvent();
    }
    
    public function testINeedATest()
    {
        $this->assertTrue(true);
    }

    // helpers

    /**
     *
     */
    protected function getUnauthIdentity()
    {
        return self::$identity->getId();
    }

    protected function getNewDocumentManager()
    {
        $client = self::createClient();
        return $client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
    }

    protected function getAuthUser($user)
    {
        return static::authUser(self::$identity, $user);
    }

    protected function verifyResponseHtml($statusCode = 200)
    {
        $this->assertEquals($statusCode, self::$client->getResponse()->getStatusCode());

        return self::$client->getResponse()->getContent();
    }

    protected function verifyResponse($statusCode, $errorCode = null, $crawler = null, $errorMessage = null)
    {
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        if (!$errorMessage) {
            $errorMessage = json_encode($data);
            if (!$data && $crawler) {
                $errorMessage = $crawler->html();
            }
        }
        $this->assertEquals($statusCode, self::$client->getResponse()->getStatusCode(), $errorMessage);
        if ($errorCode) {
            $this->assertEquals($errorCode, $data['code']);
        }

        return $data;
    }

    protected function clearRateLimit()
    {
        // clear out redis rate limiting
        self::$client->getContainer()->get('snc_redis.default')->flushdb();
    }

    protected function getValidationData($cognitoIdentityId, $validateData)
    {
        return static::$jwt->create(
            $cognitoIdentityId,
            $validateData
        );
    }

    protected function expectNoUserChangeEvent()
    {
        $this->expectUserChangeEvent($this->never());
    }

    protected function expectUserChangeEvent($count = null, $remove = false)
    {
        if (!$count) {
            $count = $this->once();
        }
        $method = 'onUserEmailChangedEvent';
        $listener = $this->getMockBuilder('UserListener')
                         ->setMethods(array($method))
                         ->getMock();
        $listener->expects($count)
                     ->method($method);

        $dispatcher = static::$container->get('event_dispatcher');
        
        if ($remove) {
            $dispatcher->removeListener(UserEmailEvent::EVENT_CHANGED, array($listener, $method));
        } else {
            $dispatcher->addListener(UserEmailEvent::EVENT_CHANGED, array($listener, $method));
        }
    }

    protected function logout()
    {
        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/logout');
        self::$client->followRedirects(false);
    }

    protected function login(
        $username,
        $password,
        $expectedLocation = null,
        $loginLocation = null,
        $expectedHttpCode = 200
    ) {
        if ($loginLocation) {
            $this->logout();
        } else {
            $loginLocation = '/login';
        }
        self::$client->followRedirects();
        $crawler = self::$client->request('GET', $loginLocation);
        self::$client->followRedirects(false);
        if ($expectedHttpCode) {
            self::verifyResponse($expectedHttpCode, null, null, 'Failed to start login');
            if ($expectedHttpCode > 200) {
                return;
            }
        }
        $this->assertEquals(
            sprintf('http://localhost/login'),
            self::$client->getHistory()->current()->getUri()
        );

        $form = $crawler->selectButton('_submit')->form();
        $form['_username'] = $username;
        $form['_password'] = $password;
        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        if ($expectedHttpCode) {
            self::verifyResponse($expectedHttpCode, null, null, 'Failed to login');
        }
        self::$client->followRedirects(false);
        if ($expectedLocation) {
            $this->assertEquals(
                sprintf('http://localhost/%s', $expectedLocation),
                self::$client->getHistory()->current()->getUri()
            );
        }

        return $crawler;
    }

    /**
     *
     * @param string $item
     *
     * check if id is one of the search form ID's
     */
    protected function isASearchFormId($item)
    {
        $keys = array(
            'search-phone-form',
            'search-phone-form-homepage',
            'search-phone-form-footer',
            'search-phone-form-header'
        );
        foreach ($keys as $key) {
            if (mb_strpos($item, $key) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     *
     * Returns list of all phone search forms, extracts data-base-path and data-path suffix
     */
    protected function verifySearchFormData($forms, $expectedPath, $numOfForms = 1)
    {
        $processed = 0;
        foreach ($forms as $form) {
            if (self::isASearchFormId($form->getAttribute('id'))) {
                $this->assertSame(
                    $expectedPath,
                    sprintf(
                        '%s%s',
                        $form->getAttribute('data-base-path'),
                        $form->getAttribute('data-path-suffix')
                    )
                );
                $processed++;
            }
        }
        $this->assertEquals($numOfForms, $processed);
    }

    protected function expectFlashSuccess($crawler, $message)
    {
        $this->assertContains(
            $message,
            $crawler->filterXPath('//div[contains(@class, "flash-success")]')->html()
        );
    }

    protected function expectFlashWarning($crawler, $message)
    {
        $this->assertContains(
            $message,
            $crawler->filterXPath('//div[contains(@class, "flash-warning")]')->html()
        );
    }

    protected function expectFlashError($crawler, $message)
    {
        $this->assertContains(
            $message,
            $crawler->filterXPath('//div[contains(@class, "flash-danger")]')->html()
        );
    }
}
