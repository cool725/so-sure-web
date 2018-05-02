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
 */
class KernelListenerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    public function testUtmCampaign()
    {
        $client = self::createClient();
        if (!$client->getContainer()) {
            throw new \Exception('unable to find container');
        }
        $crawler = $client->request('GET', '/?utm_campaign=foo');
        /** @var SessionInterface $session */
        $session = $client->getContainer()->get('session');
        $utm = unserialize($session->get('utm'));
        $this->assertEquals('foo', $utm['campaign']);
    }

    public function testUtmSource()
    {
        $client = self::createClient();
        if (!$client->getContainer()) {
            throw new \Exception('missing container');
        }
        $crawler = $client->request('GET', '/?utm_source=foo');
        /** @var SessionInterface $session */
        $session = $client->getContainer()->get('session');
        $utm = unserialize($session->get('utm'));
        $this->assertEquals('foo', $utm['source']);
    }

    public function testUtmSourcePost()
    {
        $client = self::createClient();
        if (!$client->getContainer()) {
            throw new \Exception('missing container');
        }
        $crawler = $client->request('POST', '/?utm_source=foo');
        /** @var SessionInterface $session */
        $session = $client->getContainer()->get('session');
        $utm = unserialize($session->get('utm'));
        $this->assertEquals('foo', $utm['source']);
    }

    public function testUtmMedium()
    {
        $client = self::createClient();
        if (!$client->getContainer()) {
            throw new \Exception('missing container');
        }
        $crawler = $client->request('GET', '/?utm_medium=foo');
        /** @var SessionInterface $session */
        $session = $client->getContainer()->get('session');
        $utm = unserialize($session->get('utm'));
        $this->assertEquals('foo', $utm['medium']);
    }

    public function testUtmOverride()
    {
        $client = self::createClient();
        if (!$client->getContainer()) {
            throw new \Exception('missing container');
        }
        $crawler = $client->request('GET', '/?utm_nooverride=1&utm_source=foo');
        /** @var SessionInterface $session */
        $session = $client->getContainer()->get('session');
        $this->assertNull($session->get('utm'));
    }
}
