<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\Salva;
use AppBundle\Classes\SoSure;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\PolicyTerms;

/**
 * @group unit
 */
class PhoneTest extends \PHPUnit\Framework\TestCase
{
    use DateTrait;

    /** @var PolicyTerms */
    protected static $policyTerms;

    /** @var PolicyTerms */
    protected static $nonPicSurePolicyTerms;

    public static function setUpBeforeClass()
    {
        static::$policyTerms = new PolicyTerms();
        static::$policyTerms->setVersion(PolicyTerms::VERSION_10);

        static::$nonPicSurePolicyTerms = new PolicyTerms();
        static::$nonPicSurePolicyTerms->setVersion(PolicyTerms::VERSION_1);
    }

    public function tearDown()
    {
    }

    public function testYearlyPremium()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        $this->assertEquals(95.88, $phoneA->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $this->assertEquals(101.88, $phoneB->getCurrentPhonePrice()->getYearlyPremiumPrice());
    }

    public function testMaxPot()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        // 76.704
        $this->assertEquals(76.70, $phoneA->getCurrentPhonePrice()->getMaxPot());
        // 81.504
        $this->assertEquals(81.50, $phoneB->getCurrentPhonePrice()->getMaxPot());
    }

    public function testMaxConnections()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        // 76.704
        $this->assertEquals(8, $phoneA->getCurrentPhonePrice()->getMaxConnections());
        // 81.504
        $this->assertEquals(9, $phoneB->getCurrentPhonePrice()->getMaxConnections());
    }

    public function testPolicy2dp()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.990001, static::$policyTerms, 1.5);
        /** @var PhonePrice $price */
        $price = $phone->getCurrentPhonePrice();
        $this->assertEquals(6.99, $price->getMonthlyPremiumPrice());
    }

    public function testPolicyTermsNoPicSure()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.990001, static::$nonPicSurePolicyTerms, 1.5);
        /** @var PhonePrice $price */
        $price = $phone->getCurrentPhonePrice();
        $this->assertNotNull($price->getPhoneExcess());
        if ($price->getPhoneExcess()) {
            $this->assertEquals(50, $price->getPhoneExcess()->getDamage());
            $this->assertEquals(50, $price->getPhoneExcess()->getWarranty());
            $this->assertEquals(50, $price->getPhoneExcess()->getExtendedWarranty());
            $this->assertEquals(70, $price->getPhoneExcess()->getTheft());
            $this->assertEquals(70, $price->getPhoneExcess()->getLoss());
        }
        $this->assertNull($price->getPicSureExcess());
    }

    public function testPolicyTermsPicSure()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.990001, static::$policyTerms, 1.5);
        /** @var PhonePrice $price */
        $price = $phone->getCurrentPhonePrice();

        $this->assertNotNull($price->getPicSureExcess());
        if ($price->getPicSureExcess()) {
            $this->assertEquals(50, $price->getPicSureExcess()->getDamage());
            $this->assertEquals(50, $price->getPicSureExcess()->getWarranty());
            $this->assertEquals(50, $price->getPicSureExcess()->getExtendedWarranty());
            $this->assertEquals(70, $price->getPicSureExcess()->getTheft());
            $this->assertEquals(70, $price->getPicSureExcess()->getLoss());
        }

        $this->assertNotNull($price->getPhoneExcess());
        if ($price->getPhoneExcess()) {
            $this->assertEquals(150, $price->getPhoneExcess()->getDamage());
            $this->assertEquals(150, $price->getPhoneExcess()->getWarranty());
            $this->assertEquals(150, $price->getPhoneExcess()->getExtendedWarranty());
            $this->assertEquals(150, $price->getPhoneExcess()->getTheft());
            $this->assertEquals(150, $price->getPhoneExcess()->getLoss());
        }
    }

    public function testIsSameMake()
    {
        $this->assertTrue($this->getSamplePhoneA()->isSameMake('APPLE'));
        $this->assertFalse($this->getSamplePhoneA()->isSameMake('LG'));

        $phone = new Phone();
        $phone->setMake('LG');
        $this->assertTrue($phone->isSameMake('lge'));

        $phone = new Phone();
        $phone->setMake('LG');
        $phone->setAlternativeMake('Google');
        $this->assertTrue($phone->isSameMake('Google'));

        $phone = new Phone();
        $phone->setMake('Apple');
        $this->assertFalse($phone->isSameMake('Google'));
    }

    /**
     * @expectedException \Exception
     */
    public function testChangePriceNoBinder()
    {
        $phone = new Phone();
        $phone->init('Apple', 'Binder', 9, static::$policyTerms, 32, ['binder'], 5000);
        $this->assertNull($phone->getSalvaBinderMonthlyPremium());
        $this->assertNull($phone->getSalvaMiniumumBinderMonthlyPremium());
        $phone->changePrice(
            9,
            \DateTime::createFromFormat('U', time()),
            static::$policyTerms->getDefaultExcess(),
            static::$policyTerms->getDefaultPicSureExcess()
        );
    }

    public function testChangePriceOneDay()
    {
        $phone = new Phone();
        $phone->init('Apple', 'Price', 9, static::$nonPicSurePolicyTerms, 32, ['time'], 500);
        $this->assertNotNull($phone->getSalvaBinderMonthlyPremium());
        $this->assertNotNull($phone->getSalvaMiniumumBinderMonthlyPremium());
        $this->assertNotNull($phone->getCurrentPhonePrice());
        if ($phone->getCurrentPhonePrice()) {
            $excess = $phone->getCurrentPhonePrice()->getPhoneExcess();
            $this->assertNotNull($excess);
            if ($excess) {
                $this->assertTrue($excess->equal(PolicyTerms::getLowExcess()));
            }
        }

        $future = \DateTime::createFromFormat('U', time());
        // TODO: 1 day fails on 26.10.18 as BST is ending. Fix calc at somepoint in the future
        $future = $this->addBusinessDays($future, 2);
        $phone->changePrice(
            9,
            $future,
            static::$policyTerms->getDefaultExcess(),
            static::$policyTerms->getDefaultPicSureExcess()
        );

        $this->assertNotNull($phone->getCurrentPhonePrice($future));
        if ($phone->getCurrentPhonePrice($future)) {
            $excess = $phone->getCurrentPhonePrice($future)->getPhoneExcess();
            $this->assertNotNull($excess);
            if ($excess) {
                $this->assertTrue($excess->equal(PolicyTerms::getHighExcess()));
            }
        }
    }

    /**
     * @expectedException \Exception
     */
    public function testChangePriceOneDayBefore()
    {
        $phone = new Phone();
        $phone->init('Apple', 'OneDayBefore', 9, static::$policyTerms, 32, ['time'], 500);
        $this->assertNotNull($phone->getSalvaBinderMonthlyPremium());
        $this->assertNotNull($phone->getSalvaMiniumumBinderMonthlyPremium());
        $future = \DateTime::createFromFormat('U', time());
        $future = $this->addBusinessDays($future, 1);
        // 1 hour in case of daylight savings time
        $future = $future->sub(new \DateInterval('PT1H'));
        $future = $future->sub(new \DateInterval('PT1S'));
        $phone->changePrice(
            9,
            $future,
            static::$policyTerms->getDefaultExcess(),
            static::$policyTerms->getDefaultPicSureExcess()
        );
    }

    /**
     * @expectedException \Exception
     */
    public function testChangePriceImmediate()
    {
        $phone = new Phone();
        $phone->init('Apple', 'Immediate', 9, static::$policyTerms, 32, ['time'], 500);
        $this->assertNotNull($phone->getSalvaBinderMonthlyPremium());
        $this->assertNotNull($phone->getSalvaMiniumumBinderMonthlyPremium());
        $phone->changePrice(
            9,
            \DateTime::createFromFormat('U', time()),
            static::$policyTerms->getDefaultExcess(),
            static::$policyTerms->getDefaultPicSureExcess()
        );
    }

    /**
     * @expectedException \Exception
     */
    public function testChangePricePast()
    {
        $phone = new Phone();
        $phone->init('Apple', 'Past', 9, static::$policyTerms, 32, ['time'], 500);
        $this->assertNotNull($phone->getSalvaBinderMonthlyPremium());
        $this->assertNotNull($phone->getSalvaMiniumumBinderMonthlyPremium());
        $past = \DateTime::createFromFormat('U', time());
        $past = $past->sub(new \DateInterval('P7D'));
        $phone->changePrice(
            9,
            $past,
            static::$policyTerms->getDefaultExcess(),
            static::$policyTerms->getDefaultPicSureExcess()
        );
    }

    /**
     * Will break when binder changes
     */
    public function testBinderCurrent()
    {
        $phone1000 = new Phone();
        $phone1000->init('Apple', '1000Binder', 9, static::$policyTerms, 32, ['1000-binder'], 1000);
        $phone1250 = new Phone();
        $phone1250->init('Apple', '1250Binder', 9, static::$policyTerms, 32, ['1250-binder'], 1250);
        $this->assertEquals(10.49, $phone1000->getSalvaBinderMonthlyPremium());
        $this->assertEquals(11.49, $phone1250->getSalvaBinderMonthlyPremium());
    }

    public function testBinder2017()
    {
        $phone1000 = new Phone();
        $phone1000->init('Apple', '1000Binder', 9, static::$policyTerms, 32, ['1000-binder'], 1000);
        $phone1250 = new Phone();
        $phone1250->init('Apple', '1250Binder', 9, static::$policyTerms, 32, ['1250-binder'], 1250);
        $binder2017 = new \DateTime('2017-01-01 00:00:00', SoSure::getSoSureTimezone());
        $this->assertEquals(10.49, $phone1000->getSalvaBinderMonthlyPremium($binder2017));
        $this->assertEquals(null, $phone1250->getSalvaBinderMonthlyPremium($binder2017));
    }

    public function testBinder2018()
    {
        $phone1000 = new Phone();
        $phone1000->init('Apple', '1000Binder', 9, static::$policyTerms, 32, ['1000-binder'], 1000);
        $phone1250 = new Phone();
        $phone1250->init('Apple', '1250Binder', 9, static::$policyTerms, 32, ['1250-binder'], 1250);
        $phone1500 = new Phone();
        $phone1500->init('Apple', '1500Binder', 9, static::$policyTerms, 32, ['1500-binder'], 1500);
        $binder2018 = new \DateTime('2018-01-01 00:00:00', SoSure::getSoSureTimezone());
        $this->assertEquals(10.49, $phone1000->getSalvaBinderMonthlyPremium($binder2018));
        $this->assertEquals(11.49, $phone1250->getSalvaBinderMonthlyPremium($binder2018));
        $this->assertEquals(12.49, $phone1500->getSalvaBinderMonthlyPremium($binder2018));
    }

    /**
     * @expectedException \Exception
     */
    public function testPostBinder()
    {
        $phone = new Phone();
        $phone->init('Apple', 'PostBinder', 9, static::$policyTerms, 32, ['post-binder'], 1000);
        $phone->getSalvaBinderMonthlyPremium(Salva::getSalvaBinderEndDate());
    }

    /**
     * @expectedException \Exception
     */
    public function testPlusEncoding()
    {
        $phone = new Phone();
        $phone->setModel('A-Plus');
    }

    /**
     * Makes sure that getOrderedPhonePrices gives all phone prices in descending order.
     */
    public function testGetOrderedPhonePrices()
    {
        $phone = new Phone();
        $priceA = new PhonePrice();
        $priceB = new PhonePrice();
        $priceC = new PhonePrice();
        $priceA->setValidFrom(new \DateTime('2018-02-19'));
        $priceB->setValidFrom(new \DateTime('2019-05-02'));
        $priceC->setValidFrom(new \DateTime('2020-11-08'));
        $this->assertEquals([], $phone->getOrderedPhonePrices());
        $phone->addPhonePrice($priceB);
        $phone->addPhonePrice($priceA);
        $phone->addPhonePrice($priceC);
        $this->assertEquals([$priceC, $priceB, $priceA], $phone->getOrderedPhonePrices());
    }

    /**
     * Makes sure that getCurrentPhonePrice gets the phone price that is current so long as there is one, and uses no
     * maximum dates.
     */
    public function testGetCurrentPhonePrice()
    {
        $phone = new Phone();
        $priceA = new PhonePrice();
        $priceB = new PhonePrice();
        $priceC = new PhonePrice();
        $priceA->setValidFrom(new \DateTime('2018-02-19'));
        $priceB->setValidFrom(new \DateTime('2019-05-02'));
        $priceC->setValidFrom(new \DateTime('2020-11-08'));
        $this->assertNull($phone->getCurrentPhonePrice());
        $phone->addPhonePrice($priceB);
        $phone->addPhonePrice($priceA);
        $phone->addPhonePrice($priceC);
        $this->assertEquals($priceB, $phone->getCurrentPhonePrice());
        $this->assertEquals($priceC, $phone->getCurrentPhonePrice(new \DateTime('2050-01-01')));
    }

    /**
     * Makes sure that getPreviousPhonePrices gets the list of all phone prices that are old and does not include the
     * current phone price.
     */
    public function testGetPreviousPhonePrices()
    {
        $phone = new Phone();
        $priceA = new PhonePrice();
        $priceB = new PhonePrice();
        $priceC = new PhonePrice();
        $priceA->setValidFrom(new \DateTime('2018-02-19'));
        $priceB->setValidFrom(new \DateTime('2019-05-02'));
        $priceC->setValidFrom(new \DateTime('2020-11-08'));
        $this->assertEquals([], $phone->getPreviousPhonePrices());
        $phone->addPhonePrice($priceB);
        $phone->addPhonePrice($priceA);
        $phone->addPhonePrice($priceC);
        $this->assertEquals([$priceA], $phone->getPreviousPhonePrices());
        $this->assertEquals([$priceB, $priceA], $phone->getPreviousPhonePrices(new \DateTime('2050-01-01')));
    }

    /**
     * Makes sure that getFuturePhonePrices gets all phone prices that appear in the future not including the current
     * price.
     */
    public function testGetFuturePhonePrices()
    {
        $phone = new Phone();
        $priceA = new PhonePrice();
        $priceB = new PhonePrice();
        $priceC = new PhonePrice();
        $priceA->setValidFrom(new \DateTime('2018-02-19'));
        $priceB->setValidFrom(new \DateTime('2019-05-02'));
        $priceC->setValidFrom(new \DateTime('2020-11-08'));
        $this->assertEquals([], $phone->getFuturePhonePrices());
        $phone->addPhonePrice($priceB);
        $phone->addPhonePrice($priceA);
        $phone->addPhonePrice($priceC);
        $this->assertEquals([$priceC], $phone->getFuturePhonePrices());
    }

    /**
     * Makes sure that getRecentPhonePrices gets all phone prices that have been going in the last few minutes
     * including the current phone price.
     */
    public function testGetRecentPhonePrices()
    {
        $phone = new Phone();
        $priceA = new PhonePrice();
        $priceB = new PhonePrice();
        $priceC = new PhonePrice();
        $priceD = new PhonePrice();
        $date = new \DateTime();
        $priceA->setValidFrom((clone $date)->sub(new \DateInterval("P1DT5M")));
        $priceB->setValidFrom((clone $date)->sub(new \DateInterval("PT5M")));
        $priceC->setValidFrom((clone $date)->add(new \DateInterval("PT5M")));
        $priceD->setValidFrom((clone $date)->add(new \DateInterval("P1DT37M")));
        $this->assertEquals([], $phone->getRecentPhonePrices(10));
        $phone->addPhonePrice($priceB);
        $phone->addPhonePrice($priceA);
        $phone->addPhonePrice($priceC);
        $phone->addPhonePrice($priceD);
        $this->assertEquals([$priceB], $phone->getRecentPhonePrices(10));
    }

    private function getSamplePhoneA()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 5s', 6.49 + 1.5, static::$policyTerms);

        return $phone;
    }

    private function getSamplePhoneB()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.99 + 1.5, static::$policyTerms);

        return $phone;
    }
}
