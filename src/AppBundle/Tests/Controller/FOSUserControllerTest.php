<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

/**
 * @group functional-net
 */
class FOSUserControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    public function tearDown()
    {
    }

    public function testLogin()
    {
        $crawler = self::$client->request('GET', '/login');
        self::verifyResponse(200);
        $form = $crawler->selectButton('_submit')->form();
        $form['_username'] = 'patrick@so-sure.com';
        $form['_password'] = \AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD;
        self::$client->enableProfiler();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);
        /** @var EventDataCollector $eventDataCollector */
        $eventDataCollector = self::$client->getProfile()->getCollector('events');
        $listeners = $eventDataCollector->getCalledListeners();
        // @codingStandardsIgnoreStart
        $this->assertTrue(isset($listeners['security.interactive_login.actual.AppBundle\Listener\SecurityListener::onActualSecurityInteractiveLogin']));
        // @codingStandardsIgnoreEnd
    }
}
