<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\Company;
use AppBundle\Document\CustomerCompany;
use AppBundle\Document\Sanctions;
use AppBundle\Document\User;
use AppBundle\Event\CompanyEvent;
use AppBundle\Event\UserEvent;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineConnectionListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use AppBundle\Event\ConnectionEvent;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Connection\StandardConnection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use AppBundle\Listener\SanctionsListener;

/**
 * @group functional-nonet
 */
class SanctionsListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $redis;
    protected static $sanctionsService;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();
        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        self::$redis = self::$container->get('snc_redis.default');
        self::$sanctionsService = self::$container->get('app.sanctions');
    }

    public function tearDown()
    {
    }

    public function testSanctionsListener()
    {
        self::$redis->flushdb();
        $sanctions = new Sanctions();
        $sanctions->setSource(Sanctions::SOURCE_UK_TREASURY);
        $sanctions->setType(Sanctions::TYPE_USER);
        $sanctions->setFirstName('Nashwan');
        $sanctions->setLastName('ABD AL-BAQI');
        self::$dm->persist($sanctions);

        $sanctions = new Sanctions();
        $sanctions->setSource(Sanctions::SOURCE_UK_TREASURY);
        $sanctions->setType(Sanctions::TYPE_COMPANY);
        $sanctions->setCompany('Aboo ooka');
        self::$dm->persist($sanctions);

        self::$dm->flush();

        $user = new User();
        $user->setId(1);
        $user->setFirstName('Nashwan');
        $user->setLastName('ABD-AL');

        $company = new CustomerCompany();
        $company->setId(2);
        $company->setName('Aboo');

        $listener = new SanctionsListener(self::$sanctionsService, self::$redis);
        $listener->onUserCreatedEvent(new UserEvent($user));
        $listener->onCompanyCreatedEvent(new CompanyEvent($company));

        $companies = [];
        $users = [];

        while ($sanction = unserialize(self::$redis->lpop(SanctionsListener::SANCTIONS_LISTENER_REDIS_KEY))) {
            if (isset($sanction['user'])) {
                $users[] = $sanction;
                continue;
            }
            $companies[] = $sanction;
        }

        $this->assertEquals(1, count($companies));
        $this->assertEquals(1, count($users));
    }
}
