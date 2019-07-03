<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Controller\PurchaseController;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Document\Phone;
use AppBundle\Service\JudopayService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Listener\UserListener;
use AppBundle\Event\UserEmailEvent;
use Symfony\Component\BrowserKit\Tests\TestClient;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Router;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\BrowserKit\Cookie;

class BaseControllerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    /** @var \Symfony\Bundle\FrameworkBundle\Client */
    protected static $client;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $identity;
    protected static $jwt;

    /** @var Client */
    protected static $redis;
    protected static $invitationService;
    protected static $rootDir;

    /** @var Router */
    protected static $router;

    /** @var JudopayService */
    protected static $judopayService;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        /** @var \Symfony\Bundle\FrameworkBundle\Client $client */
        $client = self::createClient();
        self::$client = $client;
        self::$container = self::$client->getContainer();
        if (!self::$container) {
            throw new \Exception('unable to find container');
        }

        set_time_limit(1800);

        self::$identity = self::$container->get('app.cognito.identity');

        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;

        self::$userManager = self::$container->get('fos_user.user_manager');

        /** @var Router $router */
        $router = self::$container->get('router');
        self::$router = $router;

        self::$jwt = self::$container->get('app.jwt');

        /** @var Client $redis */
        $redis = self::$container->get('snc_redis.default');
        self::$redis = $redis;

        /** @var JudopayService $judopayService */
        $judopayService = self::$container->get('app.judopay');
        self::$judopayService = $judopayService;

        self::$policyService = self::$container->get('app.policy');
        self::$invitationService = self::$container->get('app.invitation');
        self::$rootDir = self::$container->getParameter('kernel.root_dir');
    }

    public function setUp()
    {
        parent::setUp();
        $this->expectNoUserEmailChangeEvent();
    }

    public function testINeedATest()
    {
        $this->assertTrue(true);
    }

    /**
     * Makes sure that log error generates the correct message.
     */
    public function testLogError()
    {
        $controller = new PurchaseController();
        $message = $controller->logError(
            null,
            "testLogError",
            ApiErrorCode::EX_COMMISSION,
            "testtesttest"
        );
        $this->assertEquals($message, "testLogError:<602>\ntesttesttest");
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
        if (!$client->getContainer()) {
            throw new \Exception("missing container");
        }
        return $client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
    }

    protected function getAuthUser($user)
    {
        return static::authUser(self::$identity, $user);
    }

    protected function verifyResponseHtml($statusCode = 200)
    {
        $this->assertEquals($statusCode, $this->getClientResponseStatusCode());

        return $this->getClientResponseContent();
    }

    protected function verifyResponse($statusCode, $errorCode = null, $crawler = null, $errorMessage = null)
    {
        $data = $this->getClientResponseContent();
        if ($this->isClientResponseJson()) {
            $data = json_decode($data, true);
            if (!$errorMessage) {
                $errorMessage = json_encode($data);
                if (!$data && $crawler) {
                    $errorMessage = $crawler->html();
                }
            }
        }

        if (!$errorMessage) {
            if ($crawler) {
                $errorMessage = sprintf("%s %s", $errorMessage, $this->getCrawlerFlash($crawler));
            }
        }

        $errorMessage = sprintf(
            '%s%s%s',
            $errorMessage,
            PHP_EOL,
            self::$client->getHistory()->current()->getUri()
        );

        $this->assertEquals($statusCode, $this->getClientResponseStatusCode(), $errorMessage);
        if ($errorCode) {
            $this->assertEquals($errorCode, $data['code']);
        }

        return $data;
    }

    protected function clearRateLimit()
    {
        // clear out redis rate limiting
        /** @var Client $redis */
        $redis = $this->getContainer(true)->get('snc_redis.default');
        $redis->flushdb();
    }

    protected function getValidationData($cognitoIdentityId, $validateData)
    {
        return static::$jwt->create(
            $cognitoIdentityId,
            $validateData
        );
    }

    protected function expectNoUserEmailChangeEvent()
    {
        $this->expectUserEmailChangeEvent($this->never());
    }

    protected function expectUserEmailChangeEvent($count = null, $remove = false)
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
        if (!$loginLocation) {
            $this->logout();
            $loginLocation = '/login';
        }
        self::$client->followRedirects();
        $crawler = self::$client->request('GET', $loginLocation);
        self::$client->followRedirects(false);
        if ($expectedHttpCode) {
            self::verifyResponse($expectedHttpCode, null, $crawler);
            if ($expectedHttpCode > 200) {
                return;
            }
        }
        $this->assertEquals(
            sprintf('http://localhost/login'),
            self::$client->getHistory()->current()->getUri()
        );

        $this->assertNotNull($crawler->selectButton('_submit'));
        $form = $crawler->selectButton('_submit')->form();
        $form['_username'] = $username;
        $form['_password'] = $password;
        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        if ($expectedHttpCode) {
            self::verifyResponse($expectedHttpCode, null, $crawler);
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

    protected function getCrawlerClassHtml(Crawler $crawler, $class)
    {
        $xPath = $crawler->filterXPath('//div[contains(@class, "' . $class . '")]');
        if ($xPath->count() > 0) {
            return $xPath->html();
        }

        return null;
    }

    protected function getCrawlerFlash(Crawler $crawler)
    {
        $messages = '';
        $classes = [
            'alert-success',
            'flash-success',
            'alert-warning',
            'flash-warning',
            'alert-danger',
            'flash-danger',
        ];

        foreach ($classes as $class) {
            $messages = sprintf('%s %s', $messages, $this->getCrawlerClassHtml($crawler, $class));
        }

        return $messages;
    }

    protected function expectFlashSuccess(Crawler $crawler, $message)
    {
        $rebrand = $this->getCrawlerClassHtml($crawler, 'alert-success');
        $oldSite = $this->getCrawlerClassHtml($crawler, 'flash-success');

        $this->assertContains(
            $message,
            sprintf('%s %s', $rebrand, $oldSite),
            $crawler->html()
        );
    }

    protected function expectFlashWarning(Crawler $crawler, $message)
    {
        $rebrand = $this->getCrawlerClassHtml($crawler, 'alert-warning');
        $oldSite = $this->getCrawlerClassHtml($crawler, 'flash-warning');

        $this->assertContains(
            $message,
            sprintf('%s %s', $rebrand, $oldSite)
        );
    }

    protected function expectFlashError(Crawler $crawler, $message)
    {
        $rebrand = $this->getCrawlerClassHtml($crawler, 'alert-danger');
        $oldSite = $this->getCrawlerClassHtml($crawler, 'flash-danger');

        $this->assertContains(
            $message,
            sprintf('%s %s', $rebrand, $oldSite)
        );
    }

    protected function assertHasFormAction(Crawler $crawler, string $actionUrl)
    {
        $forms = $crawler->filter('form');
        $actions = [];
        foreach ($forms as $form) {
            $actions[] = $form->getAttribute('action');
        }
        $this->assertContains($actionUrl, $actions, "Expected a form to be sent to {$actionUrl}");
    }

    /**
     * @return Container
     */
    protected function getContainer($fromClient = false)
    {
        if ($fromClient) {
            $container = self::$client->getContainer();
        } else {
            $container = self::$container;
        }

        if (!$container) {
            throw new \Exception('Unable to load container');
        }

        return $container;
    }

    /**
     * @return \Doctrine\ODM\MongoDB\DocumentManager
     */
    protected function getDocumentManager($fromClient = false)
    {
        $container = $this->getContainer($fromClient);

        /** @var \Doctrine\ODM\MongoDB\DocumentManager $dm */
        $dm = $container->get('doctrine_mongodb.odm.default_document_manager');

        return $dm;
    }

    protected function getClientResponse()
    {
        $this->assertNotNull(self::$client->getResponse(), "Expected 'self::\$client' to have a response");
        // @todo assert
        return self::$client->getResponse() ? self::$client->getResponse() : null;
    }

    protected function getClientResponseContent()
    {
        return $this->getClientResponse() ? $this->getClientResponse()->getContent() : null;
    }

    protected function isClientResponseRedirect($location = null)
    {
        if ($this->getClientResponse()) {
            return $this->getClientResponse()->isRedirect($location);
        }

        return null;
    }

    protected function isClientResponseJson()
    {
        if ($this->getClientResponse()) {
            return $this->getClientResponse()->headers->contains('Content-Type', 'application/json');
        }

        return null;
    }

    protected function getClientResponseStatusCode()
    {
        if ($this->getClientResponse()) {
            return $this->getClientResponse()->getStatusCode();
        }

        return null;
    }

    protected function assertRedirectionPath(string $path)
    {
        $responseTargetUrl = $this->getClientResponse()->getTargetUrl();

        $this->assertEquals($path, $responseTargetUrl, "Expected '$path' to match '{$responseTargetUrl}'");
    }

    protected function assertRedirectionPathPartial(string $path)
    {
        $responseTargetUrl = $this->getClientResponse()->getTargetUrl();

        $this->assertContains($path, $responseTargetUrl, "Expected '$path' to contain '{$responseTargetUrl}'");
    }

    /**
     * @deprecated prefer assertRedirectionPath
     */
    protected function getClientResponseTargetUrl()
    {
        if ($this->getClientResponse()) {
            return $this->getClientResponse()->getTargetUrl();
        }

        return null;
    }

    protected function getRandomPhoneAndSetSession($make = null)
    {
        /** @var Phone $phone */
        $phone = self::getRandomPhone(static::$dm, $make);

        $this->setPhoneSession($phone);

        return $phone;
    }

    protected function setPhoneSession(Phone $phone)
    {
        $this->assertNotNull($phone);
        // set phone in session
        $crawler = static::$client->request(
            'GET',
            static::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        self::verifyResponse(301);
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    /**
     * Log the current session in using a token instead of by manipulating forms. This login method behaves better with
     * the CSRF service and is faster.
     */
    protected function tokenLogin($email, $password)
    {
        $session = self::$container->get("session");
        $firewall = "main";
        $token = new UsernamePasswordToken($email, $password, $firewall, []);
        $session->set("_security_".$firewall, serialize($token));
        $session->save();
        $cookie = new Cookie($session->getName(), $session->getId());
        self::$client->getCookieJar()->set($cookie);
    }
}
