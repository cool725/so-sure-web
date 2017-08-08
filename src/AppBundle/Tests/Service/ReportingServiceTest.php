<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\Payment\PotRewardPayment;
use AppBundle\Document\Policy;

/**
 * @group functional-nonet
 */
class ReportingServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $dm;
    protected static $userManager;
    protected static $reporting;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$userManager = self::$container->get('fos_user.user_manager');
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$reporting = self::$container->get('app.reporting');
    }

    public function tearDown()
    {
    }

    public function testGetAllPaymentTotals()
    {
        $now = new \DateTime();
        $existing = self::$reporting->getAllPaymentTotals(false, $now);
        
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testGetAllPaymentTotals', $this),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-10-28'),
            true,
            true
        );
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $potReward = new PotRewardPayment();
        $potReward->setDate($now);
        $potReward->setAmount(1);
        $policy->addPayment($potReward);
        self::$dm->persist($potReward);
        self::$dm->flush();

        $new = self::$reporting->getAllPaymentTotals(false, $now);

        // potreward should not affect all
        $this->assertEquals($new['all']['total'], $existing['all']['total']);
        // potreward should affect potReward
        $this->assertEquals($new['potReward']['total'], $existing['potReward']['total'] + 1);
    }
}
