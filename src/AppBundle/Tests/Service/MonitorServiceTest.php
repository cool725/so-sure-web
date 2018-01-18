<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\Claim;

/**
 * @group functional-nonet
 */
class MonitorServiceIpTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $dm;
    protected static $monitor;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$monitor = self::$container->get('app.monitor');
    }

    public function tearDown()
    {
    }

    public function setUp()
    {
        parent::setUp();

        $qb = static::$dm->createQueryBuilder(Claim::class);
        $qb->remove()
            ->getQuery()
            ->execute();
    }
    
    public function testClaimsSettledUnprocessedOk()
    {
        self::$monitor->claimsSettledUnprocessed();
    }

    /**
     * @expectedException \Exception
     */
    public function testMissingName()
    {
        $this->assertFalse(self::$monitor->run('foo'));
    }

    /**
     * @expectedException \Exception
     */
    public function testMissingPartialName()
    {
        $this->assertFalse(self::$monitor->run('claimsSettledUnprocessedFoo'));
    }

    /**
     * @expectedException \Exception
     */
    public function testClaimsSettledUnprocessedFalse()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setProcessed(false);
        self::$dm->persist($claim);
        self::$dm->flush();

        self::$monitor->claimsSettledUnprocessed();
    }

    /**
     * @expectedException \Exception
     */
    public function testClaimsSettledUnprocessedNull()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_LOSS);
        self::$dm->persist($claim);
        self::$dm->flush();

        self::$monitor->claimsSettledUnprocessed();
    }
}
