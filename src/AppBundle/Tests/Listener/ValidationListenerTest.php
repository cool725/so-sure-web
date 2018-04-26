<?php

namespace AppBundle\Tests\Listener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\ValidationListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\ObjectEvent;
use AppBundle\Document\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use AppBundle\Exception\ValidationException;

/**
 * @group functional-net
 */
class ValidationListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;

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
        self::$userManager = self::$container->get('fos_user.user_manager');
    }

    public function tearDown()
    {
    }

    /**
     * @expectedException AppBundle\Exception\ValidationException
     */
    public function testUserWithInvalidEmail()
    {
        $user = new User();
        $user->setFirstName('invalid first name$[]');

        $event = new ObjectEvent($user);
        $listener = new ValidationListener(
            self::$container->get('validator'),
            self::$container->get('logger')
        );
        $listener->onValidateEvent($event);
    }

    /**
     * @expectedException AppBundle\Exception\ValidationException
     */
    public function testUserWithInvalidEmailActual()
    {
        $testUser = static::createUser(
            self::$userManager,
            'bad-email2',
            'foo'
        );
    }
}
