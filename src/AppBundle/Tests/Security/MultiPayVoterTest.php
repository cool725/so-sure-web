<?php

namespace AppBundle\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\MultiPay;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;

/**
 * @group functional-nonet
 */
class MultiPayVoterTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    protected static $container;
    protected static $multiPayVoter;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$multiPayVoter = self::$container->get('app.voter.multiPay');
    }

    public function tearDown()
    {
    }

    public function testSupportsUnknown()
    {
        $multiPay = new MultiPay();
        $this->assertFalse(self::$multiPayVoter->supports('unknown', $multiPay));
        $this->assertFalse(self::$multiPayVoter->supports('pay', null));
    }

    public function testSupports()
    {
        $multiPay = new MultiPay();
        $this->assertTrue(self::$multiPayVoter->supports('pay', $multiPay));
    }

    public function testVoteOk()
    {
        $user = new User();
        $user->setId(1);
        $token = new PreAuthenticatedToken($user, '1', 'test');

        $multiPay = new MultiPay();
        $multiPay->setPayer($user);

        $this->assertTrue(self::$multiPayVoter->voteOnAttribute('pay', $multiPay, $token));
    }

    public function testVoteDiffUser()
    {
        $user = new User();
        $user->setId(1);

        $multiPay = new MultiPay();
        $multiPay->setPayer($user);
        
        $userDiff = new User();
        $userDiff->setId(2);
        $token = new PreAuthenticatedToken($userDiff, '1', 'test');

        $this->assertFalse(self::$multiPayVoter->voteOnAttribute('pay', $multiPay, $token));
    }
}
