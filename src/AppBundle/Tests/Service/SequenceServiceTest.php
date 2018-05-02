<?php

namespace AppBundle\Tests\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\PhonePolicy;

/**
 * @group functional-nonet
 */
class SequenceServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $sms;
    protected static $sequence;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$sequence = self::$container->get('app.sequence');
         /** @var DocumentManager */
         $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$dm = $dm;
    }

    public function tearDown()
    {
    }

    public function testSequence()
    {
        for ($i = 1; $i < 1000; $i++) {
            $seq = self::$sequence->getSequenceId('PhonePolicy');
            $this->assertEquals($i, $seq);
        }
    }
}
