<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Invitation\EmailInvitation;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 */
class InvitationTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
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
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
    }

    public function tearDown()
    {
    }

    public function testReinvite()
    {
        $invitation = new EmailInvitation();
        $this->assertEquals(0, $invitation->getReinvitedCount());
        for ($i = 0; $i < $invitation->getMaxReinvitations(); $i++) {
            $invitation->reinvite();
            $this->assertTrue($invitation->canReinvite());
        }
        $invitation->reinvite();
        $this->assertFalse($invitation->canReinvite());
    }
}
