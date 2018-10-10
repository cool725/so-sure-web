<?php

namespace AppBundle\Tests\Listener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\UserListener;
use AppBundle\Listener\DoctrineUserListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\UserEvent;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use AppBundle\Tests\Controller\BaseControllerTest;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Listener\\HubspotKernelListenerTest
 */
class HubspotKernelListenerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    // @codingStandardsIgnoreStart
    const HUBSPOT_AGENT = 'Mozilla/5.0 (X11; Linux x86_64; HubSpot Single Page link check; web-crawlers+links@hubspot.com) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36 ';
    const NON_HUBSPOT_AGENT = 'Mozilla/5.0 (X11; Linux x86_64; AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36 ';
    // @codingStandardsIgnoreEnd

    /**
     * @throws \Exception
     */
    public function testHubspotGet()
    {
        $client = self::createClient();
        if (!$client->getContainer()) {
            throw new \Exception('unable to find container');
        }
        $crawler = self::$client->request('GET', '/', [], [], [
            'REMOTE_ADDR' => '70.248.28.23',
            'HTTP_USER_AGENT' => self::HUBSPOT_AGENT
        ]);
        self::verifyResponse(200);
    }

    public function testHubspotPost()
    {
        $client = self::createClient();
        if (!$client->getContainer()) {
            throw new \Exception('unable to find container');
        }
        $crawler = self::$client->request(Request::METHOD_POST, '/ops/validation', [], [], [
            'REMOTE_ADDR' => '70.248.28.23',
            'HTTP_USER_AGENT' => self::HUBSPOT_AGENT
        ]);
        self::verifyResponse(403);
    }

    public function testNonHubspotPost()
    {
        $client = self::createClient();
        if (!$client->getContainer()) {
            throw new \Exception('unable to find container');
        }
        $crawler = self::$client->request('POST', '/ops/validation', [], [], [
            'REMOTE_ADDR' => '70.248.28.23',
            'HTTP_USER_AGENT' => self::NON_HUBSPOT_AGENT
        ]);
        self::verifyResponse(200);
    }
}
