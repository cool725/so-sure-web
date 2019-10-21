<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\User;
use AppBundle\Document\Offer;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
        // see without offers present.
        $user = new User();
        $phone = new Phone();
        $priceA = new PhonePrice();
        $priceB = new PhonePrice();
        $priceA->setValidFrom(new \DateTime('2019-02-06'));
        $priceA->setStream(PhonePrice::STREAM_ALL);
        $priceB->setValidFrom(new \DateTime('2019-03-02'));
        $priceB->setStream(PhonePrice::STREAM_YEARLY);
        $phone->addPhonePrice($priceA);
        $phone->addPhonePrice($priceB);
        self::$dm->persist($user);
        self::$dm->persist($phone);
        self::$dm->flush();
        $this->assertEquals(
            ["price" => $priceA, "source" => "phone"],
            self::$priceService->userPhonePriceSource(
                $user,
                $phone,
                PhonePrice::STREAM_ANY,
                new \DateTime('2019-02-09')
            )
        );
        $this->assertEquals(
            ["price" => $priceB, "source" => "phone"],
            self::$priceService->userPhonePriceSource(
                $user,
                $phone,
                PhonePrice::STREAM_ANY,
                new \DateTime('2019-03-09')
            )
        );
        $this->assertEquals(
            ["price" => $priceA, "source" => "phone"],
            self::$priceService->userPhonePriceSource(
                $user,
                $phone,
                PhonePrice::STREAM_MONTHLY,
                new \DateTime('2019-03-09')
            )
        );
        // with offers present.
        $offerA = new Offer();
        $offerB = new Offer();
        $offerC = new Offer();
        $offerPriceA = new PhonePrice();
        $offerPriceB = new PhonePrice();
        $offerPriceC = new PhonePrice();
        $offerPriceA->setStream(PhonePrice::STREAM_MONTHLY);
        $offerPriceB->setStream(PhonePrice::STREAM_YEARLY);
        $offerPriceC->setStream(PhonePrice::STREAM_ALL);
        $offerPriceA->setValidFrom(new \DateTime("2019-03-01"));
        $offerPriceB->setValidFrom(new \DateTime("2019-03-01"));
        $offerPriceC->setValidFrom(new \DateTime("2019-03-01"));
        $offerA->setPrice($offerPriceA);
        $offerB->setPrice($offerPriceB);
        $offerC->setPrice($offerPriceC);
        $offerA->setPhone($phone);
        $offerB->setPhone($phone);
        $offerC->setPhone($phone);
        $offerA->addUser($user);
        $offerB->addUser($user);
        self::$dm->persist($offerA);
        self::$dm->persist($offerB);
        self::$dm->persist($offerC);
        self::$dm->flush();
        $this->assertEquals(
            ["price" => $offerPriceA, "source" => "offer"],
            self::$priceService->userPhonePriceSource(
                $user,
                $phone,
                PhonePrice::STREAM_MONTHLY,
                new \DateTime('2019-05-19')
            )
        );
        $this->assertEquals(
            ["price" => $offerPriceB, "source" => "offer"],
            self::$priceService->userPhonePriceSource(
                $user,
                $phone,
                PhonePrice::STREAM_YEARLY,
                new \DateTime('2019-05-19')
            )
        );
    }

    /**
     * Makes sure the price service gives the right price for a user and phone.
     */
    public function testUserPhonePrice()
    {

    }

    /**
     * Makes sure the price service gives the right price in all streams for a user and phone.
     */
    public function testUserPhonePriceStreams()
    {

    }

    /**
     * Makes sure the price service can accurately set the premium on a policy.
     */
    public function testPolicySetPhonePremium()
    {

    }
}
