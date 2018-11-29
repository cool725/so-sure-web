<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\Promotion;
use AppBundle\Document\Participation;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Service\PromotionService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Service\MailerService;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Service\\SCodeServiceTest
 */
class AffiliateServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var PromotionService */
    protected static $promotionService;

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
        /** @var AffiliateService $affiliateService */
        $promotionService = self::$container->get('app.promotion');
        self::$promotionService = $promotionService;
    }

    public function setUp()
    {
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
    }
}
