<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Address;
use AppBundle\Document\PolicyTerms;

/**
 * @group unit
 */
class PhoneExcessTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testEqual()
    {
        $excess1 = PolicyTerms::getLowExcess();
        $excess2 = PolicyTerms::getLowExcess();

        $this->assertTrue($excess1->equal($excess2));

        $excess1 = PolicyTerms::getLowExcess();
        $excess1->setDamage(1);
        $this->assertFalse($excess1->equal($excess2));

        $excess1 = PolicyTerms::getLowExcess();
        $excess1->setWarranty(1);
        $this->assertFalse($excess1->equal($excess2));

        $excess1 = PolicyTerms::getLowExcess();
        $excess1->setExtendedWarranty(1);
        $this->assertFalse($excess1->equal($excess2));

        $excess1 = PolicyTerms::getLowExcess();
        $excess1->setTheft(1);
        $this->assertFalse($excess1->equal($excess2));

        $excess1 = PolicyTerms::getLowExcess();
        $excess1->setLoss(1);
        $this->assertFalse($excess1->equal($excess2));
    }
}
