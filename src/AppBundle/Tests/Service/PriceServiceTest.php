<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Repository\Phone;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * @group cover-nonet
 */
class PriceServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use \AppBundle\Document\DateTrait;

    protected static $container;
    /** @var AffiliateService */
    protected static $priceService;
    protected static $dm;

    /**
     * @InheritDoc
     */
    public static function setUpBeforeClass()
    {
        $kernel = static::createKernel();
        $kernel->boot();
        self::$container = $kernel->getContainer();
        self::$userManager = self::$container->get('fos_user.user_manager');
        /** @var PriceService priceService */
        $priceService = self::$container->get('app.price');
        self::$priceService = $priceService;
        /** @var DocumentManager $dm */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
    }

    /**
     * Makes sure the price service can get the applicable price with it's source.
     */
    public function testUserPhonePriceSource()
    {

    }

    /**
     * Makes sure the price service gives the right price for a user and phone.
     */
    public function testUserPhonePrice()
    {

    }

    /**
     * Makes sure the p:tab
    public function testUserPhonePriceStreams()
    {

    }

    public function testPolicySetPhonePremium()
    {

    }
}
