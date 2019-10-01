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
        $this->assertEquals(95.88, $phoneA->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY)->getYearlyPremiumPrice());
        $this->assertEquals(101.88, $phoneB->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY)->getYearlyPremiumPrice());
    }

    public function testMaxPot()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        // 76.704
        $this->assertEquals(76.70, $phoneA->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getMaxPot());
        // 81.504
        $this->assertEquals(81.50, $phoneB->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getMaxPot());
    }

    public function testMaxConnections()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        // 76.704
        $this->assertEquals(8, $phoneA->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getMaxConnections());
        // 81.504
        $this->assertEquals(9, $phoneB->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getMaxConnections());
    }

    public function testPolicy2dp()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.990001, static::$policyTerms, 1.5);
        /** @var PhonePrice $price */
        $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
        $this->assertEquals(6.99, $price->getMonthlyPremiumPrice());
    }

    public function testPolicyTermsNoPicSure()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.990001, static::$nonPicSurePolicyTerms, 1.5);
        /** @var PhonePrice $price */
        $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY);
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
        $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY);

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
        $this->assertNotNull($phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY));
        if ($phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY)) {
            $excess = $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getPhoneExcess();
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

        $this->assertNotNull($phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY, $future));
        if ($phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY, $future)) {
            $excess = $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY, $future)->getPhoneExcess();
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
        $priceD = new PhonePrice();
        $priceE = new PhonePrice();
        $priceA->setValidFrom(new \DateTime('2019-01-01'));
        $priceB->setValidFrom(new \DateTime('2019-02-13'));
        $priceC->setValidFrom(new \DateTime('2019-04-08'));
        $priceD->setValidFrom(new \DateTime('2019-04-27'));
        $priceE->setValidFrom(new \DateTime('2019-05-19'));
        $priceB->setStream(PhonePrice::STREAM_YEARLY);
        $priceC->setStream(PhonePrice::STREAM_MONTHLY);
        $priceD->setStream(PhonePrice::STREAM_MONTHLY);
        // Add them out of order to show it is irrelevant.
        $phone->addPhonePrice($priceE);
        $phone->addPhonePrice($priceA);
        $phone->addPhonePrice($priceB);
        $phone->addPhonePrice($priceD);
        $phone->addPhonePrice($priceC);
        // now make sure we get what we desire.
        $this->assertEquals([$priceE, $priceA], $phone->getOrderedPhonePrices(PhonePrice::STREAM_ALL));
        $this->assertEquals([$priceE, $priceB, $priceA], $phone->getOrderedPhonePrices(PhonePrice::STREAM_YEARLY));
        $this->assertEquals(
            [$priceE, $priceD, $priceC, $priceA],
            $phone->getOrderedPhonePrices(PhonePrice::STREAM_MONTHLY)
        );
        $this->assertEquals(
            [$priceE, $priceD, $priceC, $priceB, $priceA],
            $phone->getOrderedPhonePrices(PhonePrice::STREAM_ANY)
        );
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
        $priceD = new PhonePrice();
        $priceE = new PhonePrice();
        $priceA->setValidFrom(new \DateTime('2019-01-01'));
        $priceB->setValidFrom(new \DateTime('2019-02-13'));
        $priceC->setValidFrom(new \DateTime('2019-04-08'));
        $priceD->setValidFrom(new \DateTime('2019-04-27'));
        $priceE->setValidFrom(new \DateTime('2019-05-19'));
        $priceB->setStream(PhonePrice::STREAM_YEARLY);
        $priceC->setStream(PhonePrice::STREAM_MONTHLY);
        $priceD->setStream(PhonePrice::STREAM_MONTHLY);
        // Add them out of order to show it is irrelevant.
        $phone->addPhonePrice($priceE);
        $phone->addPhonePrice($priceA);
        $phone->addPhonePrice($priceB);
        $phone->addPhonePrice($priceD);
        $phone->addPhonePrice($priceC);
        // before start none should work.
        $date = new \DateTime('2018-12-25');
        $this->assertNull($phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY, $date));
        $this->assertNull($phone->getCurrentPhonePrice(PhonePrice::STREAM_ALL, $date));
        $this->assertNull($phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY, $date));
        $this->assertNull($phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY, $date));
        // After A start all should be A.
        $date = new \DateTime('2019-01-17');
        $this->assertEquals($priceA, $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY, $date));
        $this->assertEquals($priceA, $phone->getCurrentPhonePrice(PhonePrice::STREAM_ALL, $date));
        $this->assertEquals($priceA, $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY, $date));
        $this->assertEquals($priceA, $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY, $date));
        // After B start, yearly any and all will find it.
        $date = new \DateTime('2019-02-15');
        $this->assertEquals($priceB, $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY, $date));
        $this->assertEquals($priceA, $phone->getCurrentPhonePrice(PhonePrice::STREAM_ALL, $date));
        $this->assertEquals($priceB, $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY, $date));
        $this->assertEquals($priceA, $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY, $date));
        // After C start, monthly has it's own value and all is now gone.
        $date = new \DateTime('2019-04-11');
        $this->assertEquals($priceC, $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY, $date));
        $this->assertNull($phone->getCurrentPhonePrice(PhonePrice::STREAM_ALL, $date));
        $this->assertEquals($priceB, $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY, $date));
        $this->assertEquals($priceA, $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY, $date));
        // After D start, there is a new monthly.
        $date = new \DateTime('2019-04-25');
        $this->assertEquals($priceC, $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY, $date));
        $this->assertNull($phone->getCurrentPhonePrice(PhonePrice::STREAM_ALL, $date));
        $this->assertEquals($priceB, $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY, $date));
        $this->assertEquals($priceA, $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY, $date));
        // After E start, everything becomes E.
        $date = new \DateTime('2019-05-21');
        $this->assertEquals($priceC, $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY, $date));
        $this->assertEquals($priceE, $phone->getCurrentPhonePrice(PhonePrice::STREAM_ALL, $date));
        $this->assertEquals($priceB, $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY, $date));
        $this->assertEquals($priceA, $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY, $date));
    }

    /**
     * Makes sure that getLowestCurrentPhonePrice finds the phone price that is the lowest in the streams of those that
     * are currently going.
     */
    public function testGetLowestCurrentPhonePrice()
    {
        // TODO: this.
        $this->assertFalse(false);
    }

    /**
     * Makes sure that getOldestCurrentPhonePrice finds the phone price that is the oldest in the streams of those that
     * are currently going.
     */
    public function testGetOldestCurrentPhonePrice()
    {
        // TODO: this.
        $this->assertTrue(false);
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
        $this->assertEquals([$priceB, $priceA], $phone->getRecentPhonePrices(100000));
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
