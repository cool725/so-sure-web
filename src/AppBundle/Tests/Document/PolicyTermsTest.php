<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Attribution;
use AppBundle\Document\PolicyTerms;

/**
 * @group unit
 */
class PolicyTermsTest extends \PHPUnit\Framework\TestCase
{
    public function testGetDefault()
    {
        $nonPicSureTerms = new PolicyTerms();
        $nonPicSureTerms->setVersion(PolicyTerms::VERSION_1);

        $terms = new PolicyTerms();
        $terms->setVersion(PolicyTerms::VERSION_10);

        $this->assertEquals(150, $terms->getDefaultExcess()->getDamage());
        $this->assertEquals(50, $nonPicSureTerms->getDefaultExcess()->getDamage());

        $this->assertEquals(50, $terms->getDefaultPicSureExcess()->getDamage());
        $this->assertNull($nonPicSureTerms->getDefaultPicSureExcess());
    }

    public function testIsAllowed()
    {
        $nonPicSureTerms = new PolicyTerms();
        $nonPicSureTerms->setVersion(PolicyTerms::VERSION_1);

        $terms = new PolicyTerms();
        $terms->setVersion(PolicyTerms::VERSION_10);

        $this->assertTrue($terms->isAllowedExcess(PolicyTerms::getHighExcess()));
        $this->assertFalse($terms->isAllowedExcess(PolicyTerms::getLowExcess()));
        $this->assertTrue($terms->isAllowedExcess(PolicyTerms::getLowExcess(), true));
        $this->assertFalse($terms->isAllowedExcess(PolicyTerms::getHighExcess(), true));

        $this->assertTrue($nonPicSureTerms->isAllowedExcess(PolicyTerms::getLowExcess()));
        $this->assertFalse($nonPicSureTerms->isAllowedExcess(PolicyTerms::getHighExcess()));
        $this->assertFalse($nonPicSureTerms->isAllowedExcess(PolicyTerms::getLowExcess(), true));
        $this->assertFalse($nonPicSureTerms->isAllowedExcess(PolicyTerms::getHighExcess(), true));
    }
}
