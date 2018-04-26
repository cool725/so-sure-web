<?php

namespace AppBundle\Tests\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Sanctions;

/**
 * @group functional-net
 */
class SanctionsServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $sanctions;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$sanctions = self::$container->get('app.sanctions');
         /** @var DocumentManager */
         $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$dm = $dm;
    }

    public function tearDown()
    {
    }

    public function testCheckUserSpaceless()
    {
        $sanctions = new Sanctions();
        $sanctions->setSource(Sanctions::SOURCE_UK_TREASURY);
        $sanctions->setType(Sanctions::TYPE_USER);
        $sanctions->setFirstName('Nashwan');
        $sanctions->setLastName('ABD AL-BAQI');
        self::$dm->persist($sanctions);
        self::$dm->flush();

        $user = new User();
        $user->setFirstName('Nashwan');
        $user->setLastName('AL-BAQI');
        $matches = self::$sanctions->checkUser($user);
        $this->assertTrue(count($matches) == 0);

        $user = new User();
        $user->setFirstName('Nashwan');
        $user->setLastName('ABD-AL');
        $matches = self::$sanctions->checkUser($user);
        $this->assertTrue(count($matches) > 0);
    }
}
