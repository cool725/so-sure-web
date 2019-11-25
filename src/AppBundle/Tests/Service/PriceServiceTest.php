<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\User;
use AppBundle\Document\Offer;
use AppBundle\Exception\IncorrectPriceException;
use AppBundle\Service\PriceService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 * @group fixed
 */
class PriceServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use \AppBundle\Document\DateTrait;

    protected static $container;
    /** @var PriceService */
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
    public function testUserPhonePriceStreams()
    {
        $data = $this->userData();
        $this->assertEquals(
            ["price" => $data["priceA"], "source" => $data["phone"]],
            self::$priceService->userPhonePriceSource(
                $data["user"],
                $data["phone"],
                PhonePrice::STREAM_ANY,
                new \DateTime('2019-02-09')
            )
        );
        $this->assertEquals(
            ["price" => $data["priceB"], "source" => $data["phone"]],
            self::$priceService->userPhonePriceSource(
                $data["user"],
                $data["phone"],
                PhonePrice::STREAM_ANY,
                new \DateTime('2019-03-09')
            )
        );
        $this->assertEquals(
            ["price" => $data["priceA"], "source" => $data["phone"]],
            self::$priceService->userPhonePriceSource(
                $data["user"],
                $data["phone"],
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
        $offerA->setPhone($data["phone"]);
        $offerB->setPhone($data["phone"]);
        $offerC->setPhone($data["phone"]);
        $data["user"]->addOffer($offerA);
        $data["user"]->addOffer($offerB);
        $offerA->addUser($data["user"]);
        $offerB->addUser($data["user"]);
        self::$dm->persist($offerA);
        self::$dm->persist($offerB);
        self::$dm->persist($offerC);
        self::$dm->flush();
        $this->assertEquals(
            ["price" => $offerPriceA, "source" => $offerA],
            self::$priceService->userPhonePriceSource(
                $data["user"],
                $data["phone"],
                PhonePrice::STREAM_MONTHLY,
                new \DateTime('2019-05-19')
            )
        );
        $this->assertEquals(
            ["price" => $offerPriceB, "source" => $offerB],
            self::$priceService->userPhonePriceSource(
                $data["user"],
                $data["phone"],
                PhonePrice::STREAM_YEARLY,
                new \DateTime('2019-05-19')
            )
        );
    }

    /**
     * Makes sure the price service can accurately set the premium on a policy.
     */
    public function testPolicySetPhonePremium()
    {
        $data = $this->userData();
        $offer = new Offer();
        $offerPrice = new PhonePrice();
        $offerPrice->setGwp(41);
        $offerPrice->setValidFrom(new \DateTime("2019-05-01"));
        $offerPrice->setStream(PhonePrice::STREAM_YEARLY);
        $offer->setPrice($offerPrice);
        $offer->setPhone($data["phone"]);
        $offer->addUser($data["user"]);
        $data["user"]->addOffer($offer);
        self::$dm->persist($offer);
        self::$dm->flush();
        self::$priceService->policySetPhonePremium(
            $data["policy"],
            PhonePrice::STREAM_YEARLY,
            0,
            new \DateTime('2019-04-08')
        );
        $this->assertEquals("phone", $data["policy"]->getPremium()->getSource());
        $this->assertEquals(1.23, $data["policy"]->getPremium()->getGwp());
        self::$priceService->policySetPhonePremium(
            $data["policy"],
            PhonePrice::STREAM_YEARLY,
            0,
            new \DateTime('2019-07-08')
        );
        $this->assertEquals("offer", $data["policy"]->getPremium()->getSource());
        $this->assertEquals(41, $data["policy"]->getPremium()->getGwp());
        self::$priceService->policySetPhonePremium(
            $data["policy"],
            PhonePrice::STREAM_MONTHLY,
            0,
            new \DateTime('2019-07-08')
        );
        $this->assertEquals("phone", $data["policy"]->getPremium()->getSource());
        $this->assertEquals(20, $data["policy"]->getPremium()->getGwp());
        // Yearly on price A after price B starts.
        // Monthly on price B.
    }

    /**
     * Tests determine premium to make sure it works when there are valid inputs to it.
     */
    public function testPhonePolicyDeterminePremium()
    {
        $data = $this->userData();
        // Yearly on price A befoe price b starts.
        self::$priceService->phonePolicyDeterminePremium(
            $data["policy"],
            $data["priceA"]->getYearlyPremiumPrice(),
            new \DateTime("2019-02-21")
        );
        $this->assertEquals(1, $data["policy"]->getPremiumInstallments());
        $this->assertEquals($data["priceA"]->getYearlyPremiumPrice(), $data["policy"]->getYearlyPremiumPrice());
        // Monthly on price A.
        self::$priceService->phonePolicyDeterminePremium(
            $data["policy"],
            $data["priceA"]->getMonthlyPremiumPrice(),
            new \DateTime("2019-02-06 03:00")
        );
        $this->assertEquals(12, $data["policy"]->getPremiumInstallments());
        $this->assertEquals(
            $data["priceA"]->getMonthlyPremiumPrice(),
            $data["policy"]->getPremium()->getMonthlyPremiumPrice()
        );
        // Yearly on price B.
        self::$priceService->phonePolicyDeterminePremium(
            $data["policy"],
            $data["priceB"]->getYearlyPremiumPrice(),
            new \DateTime("2019-04-01")
        );
        $this->assertEquals(1, $data["policy"]->getPremiumInstallments());
        $this->assertEquals($data["priceB"]->getYearlyPremiumPrice(), $data["policy"]->getYearlyPremiumPrice());
    }

    /**
     * Tests determine premium to make sure that when invalid inputs are sent to it it throws the right kind of
     * exception. As ugly as all these try catches are, they are necessary because otherwise it can only test for one
     * exception.
     */
    public function testPhonePolicyDeterminePremiumInvalid()
    {
        $data = $this->userData();
        $exceptions = 0;
        // Random numbers.
        try {
            self::$priceService->phonePolicyDeterminePremium($data["policy"], 3, new \DateTime("2019-03-19"));
        } catch (IncorrectPriceException $e) {
            $exceptions++;
        }
        try {
            self::$priceService->phonePolicyDeterminePremium($data["policy"], 71.23, new \DateTime("2019-06-11"));
        } catch (IncorrectPriceException $e) {
            $exceptions++;
        }
        // Yearly on A after B.
        try {
            self::$priceService->phonePolicyDeterminePremium(
                $data["policy"],
                $data["priceA"]->getYearlyPremiumPrice(),
                new \DateTime("2019-06-11")
            );
        } catch (IncorrectPriceException $e) {
            $exceptions++;
        }
        // Monthly on B
        try {
            self::$priceService->phonePolicyDeterminePremium(
                $data["policy"],
                $data["priceB"]->getMonthlyPremiumPrice(),
                new \DateTime("2019-06-11")
            );
        } catch (IncorrectPriceException $e) {
            $exceptions++;
        }
        $this->assertEquals(4, $exceptions);
    }

    /**
     * Creates data used for each test.
     */
    private function userData()
    {
        $user = new User();
        $user->setEmail(uniqid()."@yandex.com");
        $user->setFirstName(uniqid());
        $user->setLastName(uniqid());
        $phone = new Phone();
        $priceA = new PhonePrice();
        $priceB = new PhonePrice();
        $priceA->setValidFrom(new \DateTime('2019-02-06'));
        $priceA->setStream(PhonePrice::STREAM_ALL);
        $priceA->setGwp(20);
        $priceB->setValidFrom(new \DateTime('2019-03-02'));
        $priceB->setStream(PhonePrice::STREAM_YEARLY);
        $priceB->setGwp(1.23);
        $phone->addPhonePrice($priceA);
        $phone->addPhonePrice($priceB);
        $policy = new PhonePolicy();
        $policy->setPhone($phone);
        $user->addPolicy($policy);
        self::$dm->persist($user);
        self::$dm->persist($phone);
        self::$dm->persist($policy);
        self::$dm->flush();
        return [
            "user" => $user,
            "phone" => $phone,
            "policy" => $policy,
            "priceA" => $priceA,
            "priceB" => $priceB
        ];
    }
}
